<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Inbound;

use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Models\Message;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use Illuminate\Support\Facades\DB;

/**
 * FR-10 step 4: persist the message in ONE transaction with its outbox event.
 *
 * One transaction is the zero-loss contract (NFR-04, Doc 01 C-06): a message row
 * without its `message.received` event is invisible to every subscriber forever; an
 * event without its row is a lie. Neither can exist alone, so they commit together
 * or not at all — and the fan-out (AI turn decision, ticket routing, realtime,
 * unread counters) consumes the EVENT as subscribers, never as pipeline steps
 * (FR-10 step 6). The subscriber wiring lands with its owners: Doc 09 (AI), Doc 06
 * (routing), step 8 (realtime + the unread-counter rollup consumer).
 *
 * FR-11 is the one branch: inbound on a blocked conversation is STORED with
 * quarantined=true and gets NO fan-out except audit — no event, no batch-tail
 * hint, nothing a subscriber will ever see. Unblocking does not retro-fan-out:
 * nothing replays quarantined rows onto the bus, by construction — there is no
 * code path that publishes an event for an already-persisted quarantined message,
 * and the FR-11 test pins that absence. (Doc 06's spam disposition READS the
 * quarantined flag; it does not receive events for it.)
 *
 * Recency rollups (last_customer_message_at) are stamped for quarantined rows too:
 * they are storage truth, not fan-out — Doc 06's disposition view needs to see
 * WHEN the quarantined traffic arrived.
 */
final class InboundPersister
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly MessageRepository $messages,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function persist(
        string $channel,
        string $channelRefId,
        NormalizedInbound $inbound,
        string $customerId,
    ): Message {
        return DB::transaction(function () use ($channel, $channelRefId, $inbound, $customerId): Message {
            $conversation = $this->conversations->findOrCreateForInbound(
                $channel,
                $channelRefId,
                $inbound->externalChatId,
                $customerId,
            );

            if ($conversation->wasRecentlyCreated) {
                $this->events->publish('conversation.created', [
                    'tenant_id' => $conversation->getAttribute('tenant_id'),
                    'conversation_id' => $conversation->identifier(),
                    'customer_id' => $customerId,
                    'channel' => $channel,
                ]);
            }

            $quarantined = $conversation->state === 'blocked';

            $message = $this->messages->persistInbound($conversation, $inbound, $customerId, $quarantined);

            $this->conversations->touchCustomerActivity($conversation);

            if ($quarantined) {
                $this->audit->record(
                    actionKey: 'conversation.message_quarantined',
                    entity: 'message',
                    entityId: $message->identifier(),
                    actorType: AuditActor::Customer,
                    actorId: $customerId,
                    payload: [
                        'conversation_id' => $conversation->identifier(),
                        'message_id' => $message->identifier(),
                    ],
                );

                return $message;
            }

            $this->events->publish('message.received', [
                'tenant_id' => $conversation->getAttribute('tenant_id'),
                'message_id' => $message->identifier(),
                'conversation_id' => $conversation->identifier(),
                'customer_id' => $customerId,
                'kind' => $inbound->kind->value,
            ]);

            return $message;
        });
    }
}
