<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Split;

use App\Domain\Conversations\Notes\NotesWriter;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-21's split: selected messages → a new ticket IN THE SAME conversation, carrying
 * `origin_ticket_id` and the anchor rows that resolve to the exact selection —
 * "messages referenced, not moved" (BR-02, again).
 *
 * THE ANCHORS ARE PER-MESSAGE, one row each with from = to — the migration's
 * sanctioned single-message range. A staff multi-select is not necessarily
 * contiguous, and a single [min, max] span would silently sweep in every UNselected
 * message between them; AC-05's bar is "anchors resolving to the exact selected
 * messages", and per-message rows make that resolution exact by construction.
 *
 * THE CAP IS COUNTED UNDER LOCK (EC-04): the conversation row is locked FOR UPDATE
 * before counting live linked-work tickets, so two concurrent splits at 2/3 cannot
 * both pass the count and mint 4. The count is of OPEN-FAMILY linked work — EC-04's
 * own guidance ("resolve or transfer existing linked work first") says resolving one
 * frees a slot, so a resolved split target no longer occupies one.
 *
 * The split target takes NO FR-10 slot (linked_work = true is the one sanctioned
 * multi-open case — CreateTicket and the T-02 index both already honor it), starts
 * `new` and unassigned; routing it is the department chooser's + Doc 11's business.
 * The origin's system note is written by the platform voice (NotesWriter's system
 * author) with a body localized at write time.
 */
final class SplitTicket
{
    /** FR-21: "capped at 3 per conversation". */
    public const MAX_LINKED_WORK = 3;

    public function __construct(
        private readonly CreateTicket $create,
        private readonly NotesWriter $notes,
        private readonly CapabilityMapProvider $verdicts,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $messageIds
     * @param  array<string, mixed>|null  $formData
     */
    public function split(
        Ticket $origin,
        array $messageIds,
        User $actor,
        ?string $departmentId = null,
        ?array $formData = null,
        ?string $subject = null,
    ): Ticket {
        if (! $this->verdicts->verdictFor($actor, 'tickets.split')->allowed) {
            throw new DomainException('tickets.split is required (FR-21).');
        }

        // Deduplicated up front: a double-clicked selection must not mint two anchors
        // for one message.
        $selection = array_values(array_unique($messageIds));

        if ($selection === []) {
            throw new DomainException('a split with no selected messages splits nothing (FR-21).');
        }

        /** @var Conversation $conversation */
        $conversation = Conversation::query()->findOrFail($origin->conversation_id);

        // AC-05's precondition: the selection must BE messages of this conversation —
        // an id from anywhere else would mint an anchor that resolves to nothing (or
        // to another conversation's message, which is worse). Count-compare: every
        // selected id must exist here.
        $known = DB::table('messages')
            ->where('conversation_id', $conversation->identifier())
            ->whereIn('id', $selection)
            ->count();

        if ($known !== count($selection)) {
            throw new TransitionRefused(
                'split_messages_foreign',
                'the selection contains messages that are not part of this conversation (FR-21/AC-05).',
            );
        }

        return DB::transaction(function () use ($origin, $conversation, $selection, $actor, $departmentId, $formData, $subject): Ticket {
            // EC-04 under lock: the conversation row serializes concurrent splits.
            DB::selectOne('SELECT id FROM conversations WHERE id = ? FOR UPDATE', [$conversation->identifier()]);

            $live = Ticket::query()
                ->where('conversation_id', $conversation->identifier())
                ->where('linked_work', true)
                ->whereIn('status', array_map(
                    static fn (TicketStatus $s): string => $s->value,
                    array_filter(TicketStatus::cases(), static fn (TicketStatus $s): bool => $s->isOpenFamily()),
                ))
                ->count();

            if ($live >= self::MAX_LINKED_WORK) {
                throw new TransitionRefused(
                    'split_cap_reached',
                    sprintf('this conversation already carries %d live linked-work tickets — resolve or transfer existing linked work first (EC-04).', self::MAX_LINKED_WORK),
                );
            }

            $ticket = $this->create->create(new NewTicket(
                conversation: $conversation,
                source: TicketSource::Agent,
                actor: $actor,
                subject: $subject,
                departmentId: $departmentId,
                formData: $formData,
                linkedWork: true,
                originTicketId: $origin->identifier(),
            ));

            // AC-05's anchors: one row per selected message, from = to.
            foreach ($selection as $messageId) {
                DB::table('ticket_message_anchors')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $ticket->getAttribute('tenant_id'),
                    'ticket_id' => $ticket->identifier(),
                    'conversation_id' => $conversation->identifier(),
                    'from_message_id' => $messageId,
                    'to_message_id' => $messageId,
                    'kind' => 'split_selection',
                ]);
            }

            // FR-21: "origin gets a system note + link."
            $this->notes->addSystemNote($conversation, __('tickets.note.split_origin', [
                'origin' => (string) $origin->number,
                'new' => (string) $ticket->number,
                'count' => count($selection),
            ]));

            $this->events->publish('ticket.split', [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'origin_ticket_id' => $origin->identifier(),
                'new_ticket_id' => $ticket->identifier(),
                'conversation_id' => $conversation->identifier(),
                'message_count' => count($selection),
                'actor_type' => 'staff',
                'actor_id' => $actor->identifier(),
            ]);

            $this->audit->record(
                actionKey: 'ticket.split',
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: AuditActor::Staff,
                payload: [
                    'origin_ticket_id' => $origin->identifier(),
                    'new_ticket_id' => $ticket->identifier(),
                    'message_count' => count($selection),
                ],
                actorId: $actor->identifier(),
            );

            return $ticket;
        });
    }
}
