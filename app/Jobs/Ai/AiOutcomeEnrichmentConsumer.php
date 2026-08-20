<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Domain\Ai\DecisionLog\OutcomeEnrichment;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * T-08's OUTCOME ENRICHMENT consumer (the countersigned item-3 shape): a
 * durable ADR 0011 consumer on ticket.resolved / ticket.reopened that JOINS
 * the ticket id carried by the event to the AI decision rows that created
 * the ticket (ai_decisions.ticket_id — the join key the handoff writes) and
 * enriches them: resolved → `resolved`; reopened → `resolved_then_reopened`
 * (FR-20's reopen-after-AI signal; the Doc 15 honest triad). CSAT
 * enrichment joins when Doc 12 produces ([D12]); Copilot edit outcomes ride
 * FR-16 (step 10).
 *
 * IDEMPOTENT ON event_id (C-09): the enrichment writes the same outcome for
 * the same ticket however many deliveries — a second write changes nothing.
 * ai-background: enrichment never delays a customer turn.
 */
final class AiOutcomeEnrichmentConsumer extends OutboxConsumer
{
    public function handle(OutcomeEnrichment $enrichment): void
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null || ! in_array($event->name, ['ticket.resolved', 'ticket.reopened'], true)) {
            return;
        }

        $payload = json_decode($event->payload, true);
        $ticketId = is_array($payload) && is_string($payload['ticket_id'] ?? null) ? $payload['ticket_id'] : null;
        if ($ticketId === null) {
            return;
        }

        $outcome = $event->name === 'ticket.reopened' ? 'resolved_then_reopened' : 'resolved';

        // The join: every decision row that points at this ticket.
        $decisionIds = DB::table('ai_decisions')->where('ticket_id', $ticketId)->pluck('id');
        foreach ($decisionIds as $decisionId) {
            if (is_string($decisionId)) {
                $enrichment->enrich($decisionId, $outcome, ['ticket_id' => $ticketId, 'event_id' => $this->eventId]);
            }
        }
    }

    protected function queueFamily(): string
    {
        return 'ai-background';
    }
}
