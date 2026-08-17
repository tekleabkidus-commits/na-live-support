<?php

declare(strict_types=1);

namespace App\Jobs\Customers;

use App\Domain\Customers\Rollups\CustomerRollups;
use App\Domain\Customers\Rollups\CustomerRollupStatus;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * FR-03's durable rollup maintainer (ADR 0011; contract fixture
 * fixtures/customers/consumers/doc08-events.json). The relay delivers REAL
 * events now — this class receives at-least-once, so:
 *
 * IDEMPOTENT ON event_id (C-09) BY CONSTRUCTION: the event names WHICH
 * customer(s) to recompute and nothing else — every value is re-derived from
 * truth by CustomerRollups::recompute(), so a redelivery recomputes the same
 * truth into the same columns. Sabotage #12 rewrites this into an
 * incrementer and the replay proof must go red.
 *
 * The read-slice per event (the contract fixture pins it):
 *   message.received            → customer_id
 *   ticket.created              → ticket_id (customer via the ticket row)
 *   ticket.status_changed       → ticket_id ("  — covers A-13's merge doors)
 *   ticket.spam_marked/unmarked → ticket_id (N-14 exclusion flips)
 *   customer.merged             → survivor_id (the tombstone keeps stale
 *                                 rollups — it redirects, it does not render)
 *   customer.unmerged           → survivor_id + restored_id (both live again)
 */
final class RollupConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(CustomerRollups $rollups): void
    {
        /** @var object{id: string, name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();

        if ($event === null) {
            return; // replay raced retention, or a foreign id — nothing to do
        }

        /** @var array<string, mixed> $payload */
        $payload = (array) json_decode($event->payload, true);

        foreach ($this->customerIds($event->name, $payload) as $customerId) {
            $rollups->recompute($customerId);
        }

        // FS-03 (step 12): the staleness heartbeat — beaten on every APPLIED
        // event, including ones that named no customer (the gap read by
        // CustomerRollupStatus measures the handler, not the traffic).
        app(CustomerRollupStatus::class)->beat();
    }

    /** @param array<string, mixed> $payload
     * @return list<string> */
    private function customerIds(string $name, array $payload): array
    {
        if ($name === 'message.received') {
            $id = $payload['customer_id'] ?? null;

            return is_string($id) ? [$id] : [];
        }

        if ($name === 'customer.merged' || $name === 'customer.unmerged') {
            $ids = [];
            foreach (['survivor_id', 'restored_id'] as $key) {
                if (isset($payload[$key]) && is_string($payload[$key])) {
                    $ids[] = $payload[$key];
                }
            }

            return $ids;
        }

        // The ticket.* family: the id names the ticket; the CUSTOMER comes
        // from the ticket row (truth), never from the payload.
        $ticketId = $payload['ticket_id'] ?? null;
        if (! is_string($ticketId)) {
            return [];
        }

        $customerId = DB::table('tickets')->where('id', $ticketId)->value('customer_id');

        return is_string($customerId) ? [$customerId] : [];
    }
}
