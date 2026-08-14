<?php

declare(strict_types=1);

namespace App\Jobs\Customers;

use App\Domain\Customers\Search\CustomerSearchDocuments;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * T-04's indexer, DURABLE (ADR 0011; contract fixture doc08-events.json):
 * the customers_index is fed from FR-22 events — the seven the catalog names
 * Doc08/SearchIndexer on — plus the ticket-family events that change a
 * customer's SCOPE arrays (created/assigned/assignee_deactivated/
 * transferred), because a reassigned-away agent keeping a customer in their
 * search results would be a scope leak (AC-07's whole subject). The
 * RollupConsumer precedent: a Doc 08 consumer on ticket events resolves the
 * customer from the TICKET ROW (truth), never the payload.
 *
 * IDEMPOTENT ON event_id (C-09) BY CONSTRUCTION: every delivery rewrites the
 * named customers' documents FROM TRUTH (Meilisearch addDocuments is an
 * upsert keyed on id) — a replay recomputes the same document; a removal
 * replay finds it already gone.
 *
 * Meilisearch down → the write THROWS → tries ×3 → dead-letter → re-drive
 * catches up (the Doc 07 indexer's FS-03 posture; FS-01's search-side
 * degrade is CustomerSearch's job, not this one's).
 */
final class CustomerSearchIndexer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(CustomerSearchDocuments $documents): void
    {
        /** @var object{id: string, name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null) {
            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = (array) json_decode($event->payload, true);

        foreach ($this->customerIdsFor($event->name, $payload) as $customerId) {
            // writeFor reads truth: a tombstoned id REMOVES its document
            // (customer.merged needs no special case — the merged row's
            // merged_into_id decides).
            $documents->writeFor($customerId);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function customerIdsFor(string $name, array $payload): array
    {
        // The ticket family: the id names the ticket; the CUSTOMER comes from
        // the ticket row (truth), never from the payload.
        if (str_starts_with($name, 'ticket.')) {
            $ticketId = $payload['ticket_id'] ?? null;
            if (! is_string($ticketId)) {
                return [];
            }
            $customerId = DB::table('tickets')->where('id', $ticketId)->value('customer_id');

            return is_string($customerId) ? [$customerId] : [];
        }

        $ids = match ($name) {
            'customer.merged' => [$payload['survivor_id'] ?? null, $payload['merged_id'] ?? null],
            'customer.unmerged' => [$payload['survivor_id'] ?? null, $payload['restored_id'] ?? null],
            'customer.identity.unlinked' => [$payload['customer_id'] ?? null, $payload['new_customer_id'] ?? null],
            default => [$payload['customer_id'] ?? null],
        };

        return array_values(array_filter($ids, is_string(...)));
    }
}
