<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Creation;

use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FR-03's subject, both doors — and EC-11's failure branch as an ABSENCE.
 *
 * "Subject: optional at creation; if absent, AI generates one from the first messages
 * (Doc 09) within 30 s, marked subject_source=ai, editable by staff (tickets.edit)."
 *
 * DOC 09 IS THE SEAM, and it is deliberately a plain method rather than a queued job:
 * the 30-second budget and the generation itself are Doc 09's (AI runs in ITS queued
 * jobs, never inline — the stack rule), and what Doc 06 owes is a writer that stamps
 * the provenance correctly when the answer arrives.
 *
 * EC-11 NEEDS NO CODE HERE, WHICH IS THE POINT: "AI subject generation fails → subject
 * stays empty with retry affordance; lists fall back to first-message snippet (Doc
 * 07)". A failed generation simply never calls this class, so the column stays NULL —
 * there is no half-written subject and no error sentinel to clean up later. The retry
 * affordance and the snippet fallback are Doc 07's rendering of that NULL.
 */
final class SubjectWriter
{
    public function __construct(
        private readonly CapabilityMapProvider $verdicts,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Doc 09's door. `subject_source=ai` is what §8 renders as the Copilot source
     * label, and B2-S06 records that the label SURVIVES a later staff edit — so this
     * never overwrites a subject that already exists, whoever wrote it.
     */
    public function generated(Ticket $ticket, string $subject): bool
    {
        if ($ticket->subject !== null) {
            return false;
        }

        return $this->write($ticket, $subject, 'ai', null);
    }

    /** FR-03's staff door, gated on `tickets.edit`. */
    public function editedByStaff(Ticket $ticket, string $subject, User $actor): bool
    {
        if (! $this->verdicts->verdictFor($actor, 'tickets.edit')->allowed) {
            throw new DomainException('tickets.edit is required to change a ticket subject (FR-03).');
        }

        if (trim($subject) === '') {
            throw new DomainException('a subject may be absent, but it may not be blanked to an empty string (FR-03) — an empty subject is what Doc 09 fills.');
        }

        return $this->write($ticket, $subject, 'staff', $actor);
    }

    private function write(Ticket $ticket, string $subject, string $source, ?User $actor): bool
    {
        return DB::transaction(function () use ($ticket, $subject, $source, $actor): bool {
            $old = $ticket->subject;

            $written = Ticket::query()
                ->whereKey($ticket->identifier())
                ->update(['subject' => $subject, 'subject_source' => $source, 'updated_at' => DB::raw('clock_timestamp()')]);

            if ($written !== 1) {
                return false;
            }

            $ticket->refresh();

            $this->events->publish('ticket.subject_changed', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                'subject_source' => $source,
                'actor_type' => $actor instanceof User ? 'staff' : 'ai',
                'actor_id' => $actor?->identifier(),
            ]);

            $this->audit->record(
                actionKey: 'ticket.subject_changed',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: $actor instanceof User ? AuditActor::Staff : AuditActor::System,
                payload: [
                    'ticket_id' => $ticket->identifier(),
                    // SEC-03's old/new. The subject is staff- or AI-authored text about
                    // the ticket, not customer content, so it belongs in the audit
                    // entry (which has its own retention and access gates) rather than
                    // on the 13-month event stream (Q7's posture).
                    'old_subject' => $old,
                    'new_subject' => $subject,
                    'subject_source' => $source,
                ],
                actorId: $actor?->identifier(),
            );

            return true;
        });
    }
}
