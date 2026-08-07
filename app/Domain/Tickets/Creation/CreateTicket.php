<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Creation;

use App\Domain\Conversations\Storage\ConversationRepository;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Ids\TenantSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE ONE CREATION PATH (FR-12/FR-13/FR-14; FR-11 composes on it).
 *
 * FR-13 says it plainly — automation and API take "the same validation path" — and
 * FR-12/FR-14 are the same statement about the agent and offline-form doors. So there
 * is one class, and a `source` that records which door was used. The alternative (a
 * creator per path) is how the four paths drift until only three of them claim the
 * open-family slot.
 *
 * WHAT HAPPENS, IN ONE TRANSACTION:
 *   1. bound the sequence lock wait (FS-03)
 *   2. allocate the per-tenant number (FR-02, C-01: inside the OWNING transaction, so
 *      a rollback rolls the counter back and the sequence stays gap-free)
 *   3. insert the row
 *   4. claim the conversation's one open-ticket slot (FR-10/BR-05, Doc 04's column)
 *   5. publish ticket.created + audit
 *
 * ORDER MATTERS AT STEP 4. The slot is claimed AFTER the insert because the claim
 * stores the ticket id. Both the claim's refusal (`open_ticket_exists`) and the T-02
 * partial unique index sit inside this transaction, so a concurrent create loses at
 * one of them and rolls back whole — no number leaked, no half-ticket. AC-02 drives
 * exactly that race.
 *
 * NUMBERS AND GAPS. FR-02 permits gaps and this class produces them honestly: a
 * rolled-back create takes its number down with it, so the next ticket skips one. That
 * is documented in the spec ("gaps possible on rolled-back creates — acceptable and
 * documented") and it is far better than the alternative, which is allocating outside
 * the transaction and leaking a number on every failure.
 */
final class CreateTicket
{
    /**
     * FS-03's contention threshold. A deliberate constant rather than a setting: SS-03
     * fixes the four `tickets.*` settings this document registers, and inventing a
     * fifth to hold an infrastructure timeout would be scope creep into the registry.
     * Doc 18/19 owns tuning it if a real load profile ever demands it.
     */
    private const SEQUENCE_LOCK_TIMEOUT_MS = 2000;

    public function __construct(
        private readonly TenantSequence $sequence,
        private readonly ConversationRepository $conversations,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function create(NewTicket $request): Ticket
    {
        // FR-13: idempotency is checked BEFORE the transaction so a replayed request
        // is cheap, and again by the unique index inside it so a genuine race cannot
        // slip between the check and the insert (C-09: at-least-once everywhere).
        if ($request->idempotencyKey !== null) {
            $existing = Ticket::query()->where('idempotency_key', $request->idempotencyKey)->first();

            if ($existing instanceof Ticket) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($request): Ticket {
                // FS-03: bound the wait rather than piling connections onto one row
                // lock. SET LOCAL, so it dies with this transaction and cannot leak
                // into whatever the connection does next.
                DB::statement(sprintf("SET LOCAL lock_timeout = '%dms'", self::SEQUENCE_LOCK_TIMEOUT_MS));

                $number = $this->sequence->next('ticket_number');
                $ticketId = (string) Str::uuid7();

                // FR-10/BR-05, CLAIMED BEFORE THE INSERT — and the order is the point.
                //
                // §21 puts the T-02 index BEHIND the service check ("a guarantee behind
                // the service check"), so the service must be what refuses in the
                // ordinary case. Claiming after the insert inverted that: the index
                // fired first and the caller got a raw
                // `duplicate key value violates unique constraint` instead of
                // `open_ticket_exists` — the stable C-04 code Doc 04 shipped and Doc 06
                // renders. Found by CreationPathsTest on its first run.
                //
                // Claiming first needs the id first, which is why it is generated here
                // rather than by the model. Both layers still hold: the claim refuses in
                // the normal case, the index catches anything that ever bypasses it.
                //
                // Split targets (FR-21, linked_work) take no slot — the one sanctioned
                // multi-open case.
                if (! $request->linkedWork) {
                    $this->conversations->claimOpenTicket($request->conversation, $ticketId);
                }

                $ticket = new Ticket;
                $ticket->forceFill([
                    'id' => $ticketId,
                    'tenant_id' => $request->conversation->getAttribute('tenant_id'),
                    'conversation_id' => $request->conversation->identifier(),
                    'customer_id' => $request->conversation->customer_id,
                    'number' => $number,
                    // `status` IS DELIBERATELY ABSENT. The column defaults to 'new'
                    // (§4.2's entry state), so creation names it nowhere and NFR-03's
                    // "one server-side writer" stays literally true — the encapsulation
                    // test flagged this class as a second status writer until the
                    // default did the work instead.
                    'priority' => $request->priority,
                    'source' => $request->source->value,
                    'subject' => $request->subject,
                    // FR-03: a subject supplied at creation is the staff's own words.
                    // An absent one stays absent — Doc 09 fills it and marks it `ai`.
                    'subject_source' => $request->subject === null ? null : 'staff',
                    'department_id' => $request->departmentId,
                    'linked_work' => $request->linkedWork,
                    'origin_ticket_id' => $request->originTicketId,
                    'previous_ticket_id' => $request->previousTicketId,
                    'form_data' => $request->formData,
                    'idempotency_key' => $request->idempotencyKey,
                ]);
                $ticket->save();

                // The database filled `status` (its `new` default) and the R-19b
                // columns (`created_at`, `last_status_changed_at`) — none of which the
                // app named, so none of which is on this model yet. Reading it back is
                // what makes the returned object true rather than half-written; the
                // event payload and every caller below depend on it.
                $ticket->refresh();

                if ($request->tags !== []) {
                    // text[] has no Eloquent cast here; the literal is built from
                    // bindings, never string-concatenated (FR-19's free text is
                    // operator input and reaches this column verbatim).
                    $placeholders = implode(', ', array_fill(0, count($request->tags), '?'));
                    DB::update(
                        "UPDATE tickets SET tags = ARRAY[{$placeholders}]::text[] WHERE id = ?",
                        [...$request->tags, $ticket->identifier()],
                    );
                    $ticket->refresh();
                }

                $this->events->publish('ticket.created', [
                    'tenant_id' => $ticket->getAttribute('tenant_id'),
                    'ticket_id' => $ticket->identifier(),
                    'conversation_id' => $ticket->conversation_id,
                    'customer_id' => $ticket->customer_id,
                    'source' => $ticket->source,
                    'actor_type' => $request->actor instanceof User ? 'staff' : 'system',
                    'actor_id' => $request->actor?->identifier(),
                ]);

                $this->audit->record(
                    actionKey: 'ticket.created',
                    entity: 'ticket',
                    entityId: $ticket->identifier(),
                    actorType: $request->actor instanceof User ? AuditActor::Staff : AuditActor::System,
                    payload: [
                        'ticket_id' => $ticket->identifier(),
                        'conversation_id' => $ticket->conversation_id,
                        'source' => $ticket->source,
                        'number' => $ticket->number,
                    ],
                    actorId: $request->actor?->identifier(),
                );

                return $ticket;
            });
        } catch (QueryException $e) {
            // FS-03: Postgres lock_timeout is SQLSTATE 55P03.
            if ($e->getCode() === '55P03') {
                throw new SequenceContention(self::SEQUENCE_LOCK_TIMEOUT_MS);
            }

            // FR-13: the idempotency index caught a genuine race — the winner's row is
            // the answer, which is what idempotent means.
            if ($request->idempotencyKey !== null && str_contains($e->getMessage(), 'tickets_tenant_idempotency_key_unique')) {
                $winner = Ticket::query()->where('idempotency_key', $request->idempotencyKey)->first();

                if ($winner instanceof Ticket) {
                    return $winner;
                }
            }

            throw $e;
        }
    }

    /**
     * FR-12's pre-flight: does this conversation already hold an open-family ticket?
     *
     * The dialog needs the ANSWER before it offers the button, so it can show §8's jump
     * link instead ("blocked if open-family exists (FR-10), showing a jump link"). This
     * is advisory only — the authority is the claim inside create(), which is why the
     * race is safe even though this read is not.
     */
    public function openFamilyTicketFor(Conversation $conversation): ?string
    {
        $openTicketId = $conversation->open_ticket_id;

        return is_string($openTicketId) ? $openTicketId : null;
    }
}
