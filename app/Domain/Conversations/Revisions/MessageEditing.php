<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Revisions;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\DeliverablePayload;
use App\Domain\Conversations\Adapters\SupportsDelete;
use App\Domain\Conversations\Adapters\SupportsEdit;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRevision;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-21/FR-22/FR-23 + BR-03: edits and deletes NEVER rewrite history — the
 * prior content moves into message_revisions (append-only by grant: the app
 * role cannot UPDATE or DELETE revision rows), the row gets edited_at or the
 * deleted_at tombstone, and betting-dispute truth survives per retention.
 * Exposure of the preserved content is HistoryReader's, gated
 * conversations.history.full with the access itself audited (AC-06).
 *
 * Customer side (channel-originated): the inbound pipeline detects an edit by
 * external_message_id match — the frozen FR-08 contract carries no edit flag,
 * and does not need one: a normalized inbound whose external id already exists
 * IS the edit (the messages migration recorded this). Deletes have no fake-
 * channel raw shape; Doc 05 maps Telegram's reality onto customerDeleted().
 *
 * Staff side: capability-gated through the extension interfaces — a channel
 * without supports_edit simply has no staff-edit path (FR-22's "only where
 * capability supports it"); deletes are additionally gated by the seeded
 * `telegram.message.delete` permission through the Doc 03 verdict provider
 * (the KEY is wired here; Telegram's semantics are Doc 05's).
 *
 * FR-23 is absolute: AI messages are never edited post-send — corrections are
 * new messages (Doc 09's honesty rule). The refusal lives HERE, before any
 * capability or permission question.
 */
final class MessageEditing
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly AdapterRegistry $registry,
        private readonly CapabilityMapProvider $verdicts,
    ) {}

    /** FR-21: a channel-originated edit — revision + new body, original kept. */
    public function customerEdited(Message $message, ?string $newBody): Message
    {
        // C-09/NFR-04: an "edit" that changes nothing is a REDELIVERY, not an
        // edit. At-least-once is assumed everywhere, so a normalizer that
        // committed its row and died before ACKing gets replayed — and without
        // this guard that replay wrote a revision, stamped edited_at, emitted
        // message.edited and an audit entry, i.e. exactly the duplicated side
        // effects SS-04 forbids. Found by the step-9c node-kill drill: 78
        // spurious revisions across a 1k replay. Returning early keeps the
        // consumer idempotent at the domain layer, for every caller.
        if ($message->body === $newBody) {
            return $message;
        }

        return DB::transaction(function () use ($message, $newBody): Message {
            $this->writeRevision($message, 'edit', 'customer', null);

            Message::query()->whereKey($message->identifier())
                ->update(['body' => $newBody, 'edited_at' => DB::raw('now()')]);
            $message->refresh();

            $this->emit($message, 'message.edited', 'conversation.message_edited', AuditActor::Customer, null);

            return $message;
        });
    }

    /** FR-21: "deleted by customer" — a tombstone the staff still sees. */
    public function customerDeleted(Message $message): Message
    {
        return DB::transaction(function () use ($message): Message {
            $this->writeRevision($message, 'delete', 'customer', null);

            Message::query()->whereKey($message->identifier())
                ->update(['deleted_at' => DB::raw('now()')]);
            $message->refresh();

            $this->emit($message, 'message.deleted', 'conversation.message_deleted', AuditActor::Customer, null);

            return $message;
        });
    }

    /** FR-22/FR-23: staff-side outbound edit, capability-gated. */
    public function staffEdit(Message $message, User $actor, string $newBody): Message
    {
        $this->refuseAiAuthor($message);

        if ($message->direction !== 'out' || $message->delivery_state !== 'sent') {
            throw new DomainException('staff edit applies to SENT outbound messages (FR-22); pre-send content changes are composes, not edits.');
        }

        $conversation = Conversation::query()->findOrFail($message->conversation_id);
        $adapter = $this->registry->adapter($conversation->channel);

        if (! $adapter->capabilities()->supportsEdit || ! $adapter instanceof SupportsEdit) {
            throw new DomainException('this channel cannot edit sent messages (capability off) — FR-22 edits exist only where the channel allows them.');
        }

        return DB::transaction(function () use ($message, $actor, $newBody, $conversation, $adapter): Message {
            $this->writeRevision($message, 'edit', 'staff', $actor->identifier());

            Message::query()->whereKey($message->identifier())
                ->update(['body' => $newBody, 'edited_at' => DB::raw('now()')]);
            $message->refresh();

            $adapter->editMessage(
                $conversation->external_chat_id,
                (string) $message->external_message_id,
                DeliverablePayload::text($newBody),
            );

            $this->emit($message, 'message.edited', 'conversation.message_edited', AuditActor::Staff, $actor->identifier());

            return $message;
        });
    }

    /** FR-22: staff-side delete — capability AND telegram.message.delete gated. */
    public function staffDelete(Message $message, User $actor): Message
    {
        $this->refuseAiAuthor($message);

        if (! $this->verdicts->verdictFor($actor, 'telegram.message.delete')->allowed) {
            throw new DomainException('telegram.message.delete is required to delete a sent message (FR-22, Doc 03 catalog).');
        }

        $conversation = Conversation::query()->findOrFail($message->conversation_id);
        $adapter = $this->registry->adapter($conversation->channel);

        if (! $adapter->capabilities()->supportsDelete || ! $adapter instanceof SupportsDelete) {
            throw new DomainException('this channel cannot delete sent messages (capability off).');
        }

        return DB::transaction(function () use ($message, $actor, $conversation, $adapter): Message {
            $this->writeRevision($message, 'delete', 'staff', $actor->identifier());

            Message::query()->whereKey($message->identifier())
                ->update(['deleted_at' => DB::raw('now()')]);
            $message->refresh();

            $adapter->deleteMessage($conversation->external_chat_id, (string) $message->external_message_id);

            $this->emit($message, 'message.deleted', 'conversation.message_deleted', AuditActor::Staff, $actor->identifier());

            return $message;
        });
    }

    /** FR-23: corrections to AI messages are NEW messages, never edits. */
    private function refuseAiAuthor(Message $message): void
    {
        if ($message->author_type === 'ai') {
            throw new DomainException('AI messages are never edited or deleted post-send (FR-23, Doc 09 honesty rule) — send a correction instead.');
        }
    }

    private function writeRevision(Message $message, string $kind, string $source, ?string $actorId): void
    {
        MessageRevision::query()->create([
            'id' => (string) Str::uuid7(),
            'message_id' => $message->identifier(),
            'message_created_at' => $message->getAttribute('created_at'),
            'conversation_id' => $message->conversation_id,
            'revision_kind' => $kind,
            'source' => $source,
            'actor_id' => $actorId,
            'prior_body' => $message->body,
            'prior_media' => json_encode($message->media, JSON_THROW_ON_ERROR),
        ]);
    }

    private function emit(Message $message, string $event, string $auditAction, AuditActor $actor, ?string $actorId): void
    {
        $this->events->publish($event, [
            'tenant_id' => $message->getAttribute('tenant_id'),
            'message_id' => $message->identifier(),
            'conversation_id' => $message->conversation_id,
        ]);

        $this->audit->record(
            actionKey: $auditAction,
            entity: 'message',
            entityId: $message->identifier(),
            actorType: $actor,
            actorId: $actorId,
            payload: [
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->identifier(),
            ],
        );
    }
}
