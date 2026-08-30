<?php

declare(strict_types=1);

namespace App\Jobs\Routing;

use App\Domain\Routing\Capacity\AssignmentStats;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-05/FR-06/T-03 — the DURABLE maintainer of agent_assignment_stats (ADR 0011;
 * contract fixtures fixtures/routing/consumers/doc11-events.json + the tickets doc11.json
 * read-slices). IDEMPOTENT ON event_id BY CONSTRUCTION: the event names WHICH agents to
 * recompute — every value is re-derived from tickets (AssignmentStats::recompute), so a
 * redelivery recomputes the same truth. The read-slice per event:
 *   ticket.assigned             → old_assignee_id, new_assignee_id
 *   ticket.status_changed       → ticket_id (the assignee via the row) — pending moves
 *   ticket.transferred          → ticket_id (assignee_retained=false already emitted ticket.assigned)
 *   ticket.assignee_deactivated → previous_assignee_id
 * Runs on the `automation` family (ADR 0007's precedent; no new Horizon family without an ADR).
 */
final class AssignmentStatsConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(AssignmentStats $stats, \App\Domain\Routing\Estimator\Counters $counters): void
    {
        /** @var object{id: string, name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null) {
            return;
        }
        /** @var array<string, mixed> $payload */
        $payload = (array) json_decode($event->payload, true);
        foreach ($this->agentIds($event->name, $payload) as $userId) {
            $stats->recompute($userId);
        }
        // Doc 11 T-06 (S11 step 8): every assignment (routed, claimed, manual) feeds the queue's
        // rolling estimator counter — deduplicated on the event id (C-09), the queue read from the ticket.
        $this->countAssignment($counters);
    }

    private function countAssignment(\App\Domain\Routing\Estimator\Counters $counters): void
    {
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null || $event->name !== 'ticket.assigned') {
            return;
        }
        $payload = is_string($event->payload) ? (array) json_decode($event->payload, true) : [];
        if (! is_string($payload['new_assignee_id'] ?? null) || ! is_string($payload['ticket_id'] ?? null)) {
            return;
        }
        $queueId = DB::table('tickets')->where('id', $payload['ticket_id'])->value('queue_id');
        if (! is_string($queueId)) {
            return;
        }
        /** @var object{s: string} $clock */
        $clock = DB::selectOne('SELECT EXTRACT(EPOCH FROM clock_timestamp())::bigint AS s');
        $counters->record($queueId, (int) $clock->s, (string) $this->eventId);
    }

    /** @param  array<string, mixed>  $payload
     * @return list<string> */
    private function agentIds(string $name, array $payload): array
    {
        $ids = [];
        if ($name === 'ticket.assigned') {
            foreach (['old_assignee_id', 'new_assignee_id'] as $k) {
                if (isset($payload[$k]) && is_string($payload[$k])) {
                    $ids[] = $payload[$k];
                }
            }

            return $ids;
        }
        if ($name === 'ticket.assignee_deactivated') {
            $id = $payload['previous_assignee_id'] ?? null;

            return is_string($id) ? [$id] : [];
        }
        $ticketId = $payload['ticket_id'] ?? null;
        if (! is_string($ticketId)) {
            return [];
        }
        $assignee = DB::table('tickets')->where('id', $ticketId)->value('assignee_id');

        return is_string($assignee) ? [$assignee] : [];
    }
}
