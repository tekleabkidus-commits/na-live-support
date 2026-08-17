<?php

declare(strict_types=1);

namespace App\Domain\Customers\Search;

use Illuminate\Support\Facades\DB;
use Meilisearch\Exceptions\ApiException;

/**
 * The customers_index document builder — ONE composition, always from truth
 * (T-04: the index is a projection; values never come from event payloads).
 *
 * What a document carries, and why:
 *   - display_name, usernames (current + every {old} history entry — FR-06's
 *     "search matches the history"), phones, emails: T-04's searchable list.
 *   - player_id: named in the settings from day one; its VALUE lands with
 *     FR-16's storage ([D12] seeded set, step 9) — null until then, stated
 *     honestly rather than faked.
 *   - assignee_ids / department_ids: the SCOPE arrays, copied from the
 *     customer's ticket rows — the same canonical columns the ticket lists
 *     and Doc 07 search read, so customer search visibility and ticket
 *     visibility cannot drift (CustomerScope is the one brain).
 *
 * TOMBSTONES ARE REMOVED, never indexed: a merged profile redirects (BR-05)
 * but does not surface as a directory/search result of its own.
 *
 * Meilisearch errors THROW (the Doc 07 indexer's FS-03 posture): the durable
 * consumer fails into retry ×3 → dead-letter → re-drive, and never silently
 * drops a document.
 */
final class CustomerSearchDocuments
{
    public function __construct(private readonly CustomerSearchIndex $index) {}

    /** Upsert one customer's document from truth (C-09 by construction: a replay rewrites the same doc). */
    public function writeFor(string $customerId): void
    {
        /** @var object{id: string, display_name: ?string, merged_into_id: ?string}|null $customer */
        $customer = DB::table('customers')->where('id', $customerId)
            ->first(['id', 'display_name', 'merged_into_id']);
        if ($customer === null) {
            return; // consumers re-read truth; a since-deleted row indexes nothing
        }
        if ($customer->merged_into_id !== null) {
            $this->removeFor($customerId); // tombstone (FR-12): out of the index

            return;
        }

        $usernames = [];
        $phones = [];
        $emails = [];
        $nameFacts = [];
        /** @var list<object{type: ?string, value: ?string, metadata: ?string}> $identities */
        $identities = DB::table('customer_identities')->where('customer_id', $customerId)
            ->whereNotNull('type')->get(['type', 'value', 'metadata'])->all();
        foreach ($identities as $identity) {
            /** @var array<string, mixed> $metadata */
            $metadata = $identity->metadata !== null ? (array) json_decode($identity->metadata, true) : [];
            $nameFacts[] = \App\Domain\Customers\Profile\DisplayName::factsFromMetadata($identity->metadata);
            if (is_string($metadata['username'] ?? null) && $metadata['username'] !== '') {
                $usernames[] = $metadata['username'];
            }
            if (isset($metadata['history']) && is_array($metadata['history'])) {
                foreach ($metadata['history'] as $entry) {
                    if (is_array($entry) && is_string($entry['old'] ?? null) && $entry['old'] !== '') {
                        $usernames[] = $entry['old'];
                    }
                }
            }
            if ($identity->type === 'phone' && is_string($identity->value)) {
                $phones[] = $identity->value;
                // G02/§14 (step 12): "typed as it was dialled, matched
                // against the stored international form" — the NATIONAL
                // digit form joins the document so 0912… finds +2519….
                try {
                    $national = preg_replace('/\D+/', '', \App\Domain\Customers\Identity\PhoneNumber::national($identity->value));
                    if (is_string($national) && $national !== '') {
                        $phones[] = $national;
                    }
                } catch (\libphonenumber\NumberParseException) {
                    // a pre-ADR-0014 value indexes as stored, nothing more
                }
            }
            if ($identity->type === 'email' && is_string($identity->value)) {
                $emails[] = $identity->value;
            }
        }

        // The scope arrays — distinct, from the ticket rows (truth).
        /** @var list<string> $assigneeIds */
        $assigneeIds = DB::table('tickets')->where('customer_id', $customerId)
            ->whereNotNull('assignee_id')->distinct()->pluck('assignee_id')->all();
        /** @var list<string> $departmentIds */
        $departmentIds = DB::table('tickets')->where('customer_id', $customerId)
            ->whereNotNull('department_id')->distinct()->pluck('department_id')->all();

        $client = $this->index->client();
        $customers = $this->index->ensureCustomers();
        /** @var array{taskUid: int} $task */
        $task = $customers->addDocuments([[
            'id' => $customer->id,
            // FR-01's derivation (DisplayName, the one brain) — a customer
            // named only by their channel is still findable by that name.
            'display_name' => \App\Domain\Customers\Profile\DisplayName::derive($customer->display_name, $nameFacts),
            'usernames' => array_values(array_unique($usernames)),
            'phones' => $phones,
            'emails' => $emails,
            // FR-16's storage landed with amendment addition 9 (step 9b) —
            // the contractual field now carries the stored value.
            'player_id' => DB::table('customer_field_values')->where('customer_id', $customerId)
                ->where('field_key', 'player_id')->value('value'),
            'assignee_ids' => $assigneeIds,
            'department_ids' => $departmentIds,
        ]]);
        $client->waitForTask($task['taskUid']);
    }

    /** Delete one customer's document (tombstones, merges). Absent index/doc = already gone, not an error. */
    public function removeFor(string $customerId): void
    {
        $client = $this->index->client();

        try {
            /** @var array{taskUid: int} $task */
            $task = $client->index($this->index->customersIndexName())->deleteDocument($customerId);
            $client->waitForTask($task['taskUid']);
        } catch (ApiException $e) {
            if ($e->errorCode !== 'index_not_found') {
                throw $e; // a real failure retries/dead-letters like any other
            }
        }
    }
}
