<?php

declare(strict_types=1);

namespace App\Domain\Customers\Merge;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * THE CONSEQUENCES, from live queries at confirm time (FR-11's step 3;
 * inherited row 6's law: the checklist is server-computed from the SAME
 * queries the merge executes — never a second engine). The saga executes
 * exactly this plan; the confirm step renders exactly this plan; AC-03's
 * snapshot is built from exactly this plan. One brain.
 *
 * FR-12's open-family collision is COMPUTED here (both sides hold open
 * tickets → every open ticket beyond the OLDEST gets linked_work=true,
 * sanctioned multi-open per Doc 06 FR-21, surfaced in the checklist) and
 * EC-12's cap REFUSES here (combined open linked_work > 3 → MergeBlocked
 * with the exact tickets).
 *
 * Chain law (the Doc 06 A-13 shape): neither side may already be a tombstone
 * — a merged-away profile's only path is unmerge (EC-04's outermost-first),
 * and merging INTO a tombstone would fork the redirect chain.
 */
final class MergePlan
{
    private const OPEN_FAMILY = ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'];

    /**
     * @return array{
     *   survivor: object{id: string, display_name: ?string, language: ?string, language_source: ?string, country: ?string, country_source: ?string, tags: string, merged_into_id: ?string},
     *   merged: object{id: string, display_name: ?string, language: ?string, language_source: ?string, country: ?string, country_source: ?string, tags: string, merged_into_id: ?string},
     *   conversations: list<string>, tickets: list<string>, notes: list<string>, identities: list<string>,
     *   linked_work_to_flag: list<string>, open_linked_work_after: list<string>,
     *   field_diff: array<string, array{survivor: mixed, merged: mixed}>,
     *   tags_union: list<string>,
     * }
     */
    public function build(string $survivorId, string $mergedId): array
    {
        if ($survivorId === $mergedId) {
            throw new InvalidArgumentException('a customer cannot merge into itself');
        }

        /** @var object{id: string, display_name: ?string, language: ?string, language_source: ?string, country: ?string, country_source: ?string, tags: string, merged_into_id: ?string}|null $survivor */
        $survivor = DB::table('customers')->where('id', $survivorId)->first();
        /** @var object{id: string, display_name: ?string, language: ?string, language_source: ?string, country: ?string, country_source: ?string, tags: string, merged_into_id: ?string}|null $merged */
        $merged = DB::table('customers')->where('id', $mergedId)->first();

        if ($survivor === null || $merged === null) {
            throw new InvalidArgumentException('customer not found');
        }
        if ($survivor->merged_into_id !== null || $merged->merged_into_id !== null) {
            throw new InvalidArgumentException('a tombstone cannot take part in a merge — undo its own merge first (EC-04)');
        }

        /** @var list<string> $conversations */
        $conversations = DB::table('conversations')->where('customer_id', $mergedId)->orderBy('created_at')->pluck('id')->all();
        /** @var list<string> $tickets */
        $tickets = DB::table('tickets')->where('customer_id', $mergedId)->orderBy('created_at')->pluck('id')->all();
        /** @var list<string> $notes */
        $notes = DB::table('customer_notes')->where('customer_id', $mergedId)->orderBy('created_at')->pluck('id')->all();
        /** @var list<string> $identities */
        $identities = DB::table('customer_identities')->where('customer_id', $mergedId)->orderBy('created_at')->pluck('id')->all();

        // FR-12: open-family collision — ALL open tickets across both sides,
        // oldest first; the oldest keeps the plain slot, every newer one is
        // flagged linked_work (unless it already is).
        /** @var list<object{id: string, linked_work: bool, customer_id: string}> $openTickets */
        $openTickets = DB::table('tickets')
            ->whereIn('customer_id', [$survivorId, $mergedId])
            ->whereIn('status', self::OPEN_FAMILY)
            ->orderBy('created_at')
            ->get(['id', 'linked_work', 'customer_id'])
            ->all();

        $bothSidesOpen = count(array_unique(array_map(static fn (object $t): string => $t->customer_id, array_filter($openTickets, static fn (object $t): bool => ! $t->linked_work)))) > 1;

        $toFlag = [];
        $openLinkedAfter = [];
        $first = true;
        foreach ($openTickets as $ticket) {
            if ($ticket->linked_work) {
                $openLinkedAfter[] = $ticket->id;

                continue;
            }
            if ($first) {
                $first = false;

                continue;   // the oldest plain open ticket keeps the slot
            }
            if ($bothSidesOpen) {
                $toFlag[] = $ticket->id;
                $openLinkedAfter[] = $ticket->id;
            }
        }

        if (count($openLinkedAfter) > 3) {
            throw new MergeBlocked($openLinkedAfter);
        }

        // FR-11's per-field diff: name, language, country (custom fields are
        // [D12]'s definitions — fixture-fed values render at step 11).
        $fieldDiff = [];
        foreach (['display_name', 'language', 'language_source', 'country', 'country_source'] as $field) {
            if ($survivor->{$field} !== $merged->{$field}) {
                $fieldDiff[$field] = ['survivor' => $survivor->{$field}, 'merged' => $merged->{$field}];
            }
        }

        $tagsUnion = array_values(array_unique(array_merge(
            self::pgArray((string) $survivor->tags),
            self::pgArray((string) $merged->tags),
        )));

        return [
            'survivor' => $survivor,
            'merged' => $merged,
            'conversations' => $conversations,
            'tickets' => $tickets,
            'notes' => $notes,
            'identities' => $identities,
            'linked_work_to_flag' => $toFlag,
            'open_linked_work_after' => $openLinkedAfter,
            'field_diff' => $fieldDiff,
            'tags_union' => $tagsUnion,
        ];
    }

    /** @return list<string> */
    public static function pgArray(string $raw): array
    {
        $trimmed = trim($raw, '{}');

        return $trimmed === '' ? [] : array_map(static fn (string $t): string => trim($t, '"'), explode(',', $trimmed));
    }
}
