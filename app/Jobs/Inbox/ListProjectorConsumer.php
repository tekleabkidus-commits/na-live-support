<?php

declare(strict_types=1);

namespace App\Jobs\Inbox;

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * Doc 07 T-01's durable consumer (ADR 0011, ConsumerRegistry entry — never a
 * relay edit): re-reads the event row under its own tenant context and hands
 * it to the projector.
 *
 * C-09 — HOW this consumer is idempotent on event_id: the projector is
 * RECOMPUTE-FROM-TRUTH (every value re-read from canonical rows; membership
 * re-evaluated; ZSETs are sets), so a redelivered event recomputes identical
 * state; the one insert-shaped effect (the note.mentioned bell row) is
 * deduped by the inbox_notifications event-dedupe index — a database
 * refusal, not consumer care. Proven in ListProjectorTest (and sabotage #9
 * turns the dedupe off to show the proof bites).
 *
 * Rides the `automation` family (ADR 0007's established families; the
 * TicketReopenConsumer precedent).
 */
final class ListProjectorConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(ListProjector $projector): void
    {
        /** @var object{id: string, tenant_id: string, name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();

        if ($event === null) {
            // The relay dispatched, the events insert is durable — a missing
            // row here means replay raced retention or the id is foreign;
            // nothing to project either way.
            return;
        }

        $projector->applyEvent($event);
    }
}
