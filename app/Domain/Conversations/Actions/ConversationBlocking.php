<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Actions;

use App\Domain\Conversations\Storage\ConversationRepository;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FR-03's block/unblock as ACTIONS — the step-2 repository left state-only
 * seams; this closes them with the conversation.blocked|unblocked events and
 * audit entries (FR-26), in one transaction with the state change.
 *
 * What blocking MEANS is enforced elsewhere, by construction: BR-02 refusals at
 * compose (step 4), EC-04 cancellation in the lane worker (step 4), FR-11
 * quarantine on inbound (step 3). This class only makes the state change
 * observable — and FR-11's documented rule holds here too: unblocking does NOT
 * retro-fan-out anything that arrived while blocked.
 */
final class ConversationBlocking
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function block(Conversation $conversation, ?User $actor, string $reason): Conversation
    {
        if ($conversation->state === 'blocked') {
            throw new DomainException('conversation is already blocked.');
        }

        return DB::transaction(function () use ($conversation, $actor, $reason): Conversation {
            $this->conversations->block($conversation);
            $this->record($conversation, 'conversation.blocked', $actor, $reason);

            return $conversation;
        });
    }

    public function unblock(Conversation $conversation, ?User $actor, string $reason): Conversation
    {
        if ($conversation->state !== 'blocked') {
            throw new DomainException('conversation is not blocked.');
        }

        return DB::transaction(function () use ($conversation, $actor, $reason): Conversation {
            $this->conversations->unblock($conversation);
            $this->record($conversation, 'conversation.unblocked', $actor, $reason);

            return $conversation;
        });
    }

    private function record(Conversation $conversation, string $event, ?User $actor, string $reason): void
    {
        $this->events->publish($event, [
            'tenant_id' => $conversation->getAttribute('tenant_id'),
            'conversation_id' => $conversation->identifier(),
        ]);

        $this->audit->record(
            actionKey: $event,
            entity: 'conversation',
            entityId: $conversation->identifier(),
            actorType: $actor instanceof User ? AuditActor::Staff : AuditActor::System,
            actorId: $actor?->identifier(),
            payload: [
                'conversation_id' => $conversation->identifier(),
                'reason' => $reason,
            ],
        );
    }
}
