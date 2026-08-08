<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Notes;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FR-17: internal notes — the customer's thread is sacred (BR-01) and a note is
 * the thing that must NEVER cross it. The isolation is layered, none of it here
 * by discipline:
 *  - type level (step 1): DeliverablePayload has no note constructor and
 *    NormalizedInbound refuses the kind — a note cannot BE a deliverable;
 *  - row level (step 2): the messages CHECK pins (kind='note') = is_internal;
 *  - path level (step 4): ComposeOutbound throws on kind=note before any row
 *    exists — the runtime guard AC-04 names.
 * This class is just the one sanctioned WRITER: is_internal=true, direction
 * 'in' (no delivery lifecycle — the outbound CHECK would demand a state), with
 * the note.added event and audit entry in the row's transaction.
 *
 * @mentions (FR-17): '@username' tokens matched against THIS tenant's staff;
 * each match emits note.mentioned (name filed as interpretation A-4) — the
 * notification consumer is Doc 07/18's, we only put the fact on the bus.
 *
 * PERMISSIONS are the ROUTE's obligation: tickets.note gates writing,
 * customers.notes.view gates reading, both seeded in the Doc 03 catalog;
 * Doc 07 mounts the surfaces with RequirePermission and the mapping gate
 * enforces the wiring the moment those routes exist.
 */
final class NotesWriter
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function addNote(Conversation $conversation, User $author, string $body): Message
    {
        if (trim($body) === '') {
            throw new DomainException('an empty note is not a note.');
        }

        return DB::transaction(function () use ($conversation, $author, $body): Message {
            $note = Message::query()->create([
                'conversation_id' => $conversation->identifier(),
                'direction' => 'in',
                'author_type' => 'agent',
                'author_id' => $author->identifier(),
                'kind' => 'note',
                'body' => $body,
                'is_internal' => true,
            ]);

            $payload = [
                'tenant_id' => $conversation->getAttribute('tenant_id'),
                'message_id' => $note->identifier(),
                'conversation_id' => $conversation->identifier(),
                'author_id' => $author->identifier(),
            ];

            $this->events->publish('note.added', $payload);

            $this->audit->record(
                actionKey: 'conversation.note_added',
                entity: 'message',
                entityId: $note->identifier(),
                actorType: AuditActor::Staff,
                actorId: $author->identifier(),
                payload: [
                    'conversation_id' => $conversation->identifier(),
                    'message_id' => $note->identifier(),
                ],
            );

            foreach ($this->mentionedUserIds($body) as $mentionedId) {
                $this->events->publish('note.mentioned', $payload + ['mentioned_user_id' => $mentionedId]);
            }

            return $note;
        });
    }

    /**
     * The PLATFORM's note: author_type `system`, no author id — the voice Doc 06's
     * operations speak in (FR-21's split origin note, EC-05's fallback note, EC-06's
     * hand-back). Same species as every note (kind=note, is_internal=true, N-04's
     * structural undeliverability), same event; only the author differs. The messages
     * CHECK admits `system` as an author_type from Doc 04 — this is its first writer.
     *
     * The body is localized AT WRITE TIME into the app locale: a note is an immutable
     * historical record, and re-rendering history in whatever locale a later reader
     * uses would make two staff members remember different pasts.
     */
    public function addSystemNote(Conversation $conversation, string $body): Message
    {
        if (trim($body) === '') {
            throw new DomainException('an empty note is not a note.');
        }

        return DB::transaction(function () use ($conversation, $body): Message {
            $note = Message::query()->create([
                'conversation_id' => $conversation->identifier(),
                'direction' => 'in',
                'author_type' => 'system',
                'author_id' => null,
                'kind' => 'note',
                'body' => $body,
                'is_internal' => true,
            ]);

            $this->events->publish('note.added', [
                'tenant_id' => $conversation->getAttribute('tenant_id'),
                'message_id' => $note->identifier(),
                'conversation_id' => $conversation->identifier(),
                'author_id' => null,
            ]);

            $this->audit->record(
                actionKey: 'conversation.note_added',
                entity: 'message',
                entityId: $note->identifier(),
                actorType: AuditActor::System,
                payload: [
                    'conversation_id' => $conversation->identifier(),
                    'message_id' => $note->identifier(),
                ],
            );

            return $note;
        });
    }

    /**
     * '@name' tokens resolved against THIS tenant's staff (the User global
     * scope + RLS make cross-tenant mentions structurally impossible).
     *
     * @return list<string>
     */
    private function mentionedUserIds(string $body): array
    {
        preg_match_all('/@([A-Za-z0-9_.\-]+)/u', $body, $matches);

        $handles = array_values(array_unique($matches[1]));

        if ($handles === []) {
            return [];
        }

        $ids = [];

        foreach (User::query()->whereIn('name', $handles)->pluck('id') as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
