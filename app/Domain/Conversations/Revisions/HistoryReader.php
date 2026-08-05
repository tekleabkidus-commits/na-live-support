<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Revisions;

use App\Models\Message;
use App\Models\MessageRevision;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Rbac\CapabilityMapProvider;
use DomainException;
use Illuminate\Support\Collection;

/**
 * BR-03/AC-06: the ONLY door to preserved prior content. The verdict resolves
 * at read time through the Doc 03 provider, and the access itself is AUDITED —
 * "who looked at the history of a disputed message" is exactly the question a
 * betting-dispute review asks later.
 */
final class HistoryReader
{
    public function __construct(
        private readonly CapabilityMapProvider $verdicts,
        private readonly AuditLogger $audit,
    ) {}

    /** @return Collection<int, MessageRevision> oldest first */
    public function revisionsFor(Message $message, User $viewer): Collection
    {
        if (! $this->verdicts->verdictFor($viewer, 'conversations.history.full')->allowed) {
            throw new DomainException('conversations.history.full is required to view preserved message history (BR-03).');
        }

        $this->audit->record(
            actionKey: 'conversation.history_viewed',
            entity: 'message',
            entityId: $message->identifier(),
            actorType: AuditActor::Staff,
            actorId: $viewer->identifier(),
            payload: [
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->identifier(),
            ],
        );

        return MessageRevision::query()
            ->where('message_id', $message->identifier())
            ->orderBy('id')
            ->get();
    }
}
