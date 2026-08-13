<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Storage;

use App\Domain\Tickets\Transitions\TicketStatus;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * THE ONLY PLACE A TICKET'S `status` IS WRITTEN (Doc 06 T-01/NFR-03).
 *
 * NFR-03 asks for two independent proofs and this class is the subject of both: the
 * `ticket-status-writer` CI gate greps every other file for a status write, and
 * StatusWriterEncapsulationTest asserts structurally that this file is the only one.
 * Both go red if a second writer ever appears — which is the point, because the day
 * someone "just quickly" sets a status in a controller is the day the FR-24 timestamps,
 * the outbox event and the FR-10 invariant silently stop happening together.
 *
 * Only TicketTransitionService may call writeStatus(); the gate cannot express "only
 * one CALLER", so that half rests on the service being the single composition point
 * §21 mandates — and on this docblock being read.
 *
 * THE WRITE IS GUARDED (`WHERE status = :from`). Two agents resolving the same ticket
 * at the same moment (EC-07) both pass legality on the status they read; the guard
 * means the second UPDATE matches zero rows instead of clobbering, and the service
 * turns that into either a success-no-op (the other transaction reached the same
 * target — documented idempotency) or a re-evaluated refusal. FR-16's claim race is
 * the same mechanism applied to assignment.
 */
final class TicketRepository
{
    /**
     * Change status, atomically, only if the ticket is still in `$from`.
     *
     * FR-24's metric timestamps are NOT set here and cannot be: the app role has no
     * privilege on those columns (R-19b). The database's own BEFORE UPDATE trigger
     * stamps them from this very statement, which is what makes them transactional
     * with the transition rather than derived afterwards.
     *
     * @param  array<string, string|null>  $signals  FR-24 first-response signal columns
     * @param  string|null  $pendingReason  Doc 06 FR-01: written WITH a pending transition (never derived later)
     * @param  bool  $clearPendingReason  leaving the pending family clears the reason in the same statement
     * @return bool true when this call made the change
     */
    public function writeStatus(Ticket $ticket, TicketStatus $from, TicketStatus $to, array $signals = [], ?string $pendingReason = null, bool $clearPendingReason = false): bool
    {
        $update = ['status' => $to->value, 'updated_at' => DB::raw('clock_timestamp()')];

        // Doc 06 FR-01/§4.2: the reason is part of the transition's own row
        // version — the DB CHECK enforces the enum, this statement the timing.
        if ($pendingReason !== null) {
            $update['pending_reason'] = $pendingReason;
        } elseif ($clearPendingReason) {
            $update['pending_reason'] = null;
        }

        foreach ($signals as $column => $messageId) {
            if ($messageId !== null) {
                $update[$column] = $messageId;
            }
        }

        $affected = Ticket::query()
            ->whereKey($ticket->identifier())
            ->where('status', $from->value)
            ->update($update);

        return $affected === 1;
    }

    /**
     * Record a qualifying public reply WITHOUT a status change (FR-24).
     *
     * The common case: an AI or agent replies on an already-open ticket. There is no
     * transition, so there is no transition to hang the stamp on — the signal column is
     * the hook, and the trigger does the stamping (and the refusing: a note, a
     * cross-conversation message or a pre-ticket message is rejected in the database).
     *
     * Fill-only is enforced by the trigger, so calling this twice is harmless: the
     * second call cannot move an existing stamp. That is C-09 idempotency at the
     * storage layer.
     */
    public function recordPublicReply(Ticket $ticket, string $messageId, bool $isHuman): bool
    {
        $update = ['first_response_message_id' => $messageId, 'updated_at' => DB::raw('clock_timestamp()')];

        if ($isHuman) {
            $update['agent_first_response_message_id'] = $messageId;
        }

        $affected = Ticket::query()
            ->whereKey($ticket->identifier())
            ->update($update);

        return $affected === 1;
    }
}
