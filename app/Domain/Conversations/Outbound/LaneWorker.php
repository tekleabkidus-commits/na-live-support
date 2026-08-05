<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Outbound;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\DeliverablePayload;
use App\Domain\Conversations\Adapters\OutboundMessage;
use App\Domain\Conversations\Adapters\SendState;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FR-15's lane consumer: one attempt per lane per cycle, round-robin — the §12
 * fairness rule is this structure, not a scheduler (a hot conversation gets one
 * send per cycle exactly like an idle-then-active one; no lane can monopolize).
 *
 * Per head, in order:
 *  - a row already terminal (canceled/sent/…) is popped and skipped — the lane
 *    is a work queue, not truth;
 *  - EC-04: conversation blocked between compose and send → canceled, with
 *    reason `conversation_blocked`;
 *  - a failed_retryable head inside its backoff window BLOCKS the lane
 *    (out-of-order prevention) until the 10-min cap, then lane-skip: the head
 *    demotes to the tail, `conversation.lane_skipped` fires (A-4's name) with
 *    its audit entry, and the lane drains past it;
 *  - FR-16: the rate gate refusing means the head WAITS in queued — not an
 *    error, not a drop, just "not this cycle";
 *  - otherwise: queued/failed_retryable → sending → adapter.send → sent, or
 *    failed_retryable with the FR-13 backoff schedule stamped, or failed_final
 *    (non-retryable class, or attempt ×5 exhausted).
 *
 * `$now` is always a parameter (§8 no-clock rule) — the lanes:work loop passes
 * wall time; every test passes a derived instant.
 *
 * Step-4 scope: text payloads. Outbound MEDIA payloads arrive with step 5's
 * media chain (OutboundMedia exists; nothing composes it yet).
 */
final class LaneWorker
{
    /** FR-15: a blocked lane head holds the lane at most this long. */
    public const HEAD_BLOCK_CAP_SECONDS = 600;

    public function __construct(
        private readonly OutboundLanes $lanes,
        private readonly MessageRepository $messages,
        private readonly DeliveryStateMachine $machine,
        private readonly RateGate $rateGate,
        private readonly AdapterRegistry $registry,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly ChannelHealthTracker $health,
    ) {}

    /** One round-robin cycle over every active lane. Returns attempted sends. */
    public function processOnce(CarbonImmutable $now): int
    {
        $attempts = 0;

        foreach ($this->lanes->activeLanes() as $conversationId) {
            $attempts += $this->processLane($conversationId, $now) ? 1 : 0;
        }

        return $attempts;
    }

    private function processLane(string $conversationId, CarbonImmutable $now): bool
    {
        $headId = $this->lanes->peekHead($conversationId);

        if ($headId === null) {
            return false;
        }

        $message = $this->messages->findById($headId);

        if (! $message instanceof Message) {
            // A lane entry with no row: the compose transaction rolled back after
            // the push raced in — impossible via ComposeOutbound (afterCommit),
            // but the lane never trusts itself over Postgres.
            $this->lanes->popHead($conversationId);

            return false;
        }

        $state = (string) $message->delivery_state;

        if (in_array($state, ['sent', 'delivered', 'failed_final', 'canceled'], true)) {
            $this->lanes->popHead($conversationId);

            return false;
        }

        $conversation = Conversation::query()->find($message->conversation_id);

        if (! $conversation instanceof Conversation) {
            throw new DomainException('lane head references a conversation this tenant cannot see — investigate RLS context.');
        }

        // EC-04/BR-02: blocked between compose and send. A pristine queued row
        // cancels (FR-13: canceled is pre-send only); a mid-retry row has already
        // been send-attempted, so its legal exit is failed_final — same reason.
        if ($conversation->state === 'blocked') {
            $this->machine->transition(
                $message,
                $state === 'queued' ? DeliveryState::Canceled : DeliveryState::FailedFinal,
                reason: 'conversation_blocked',
            );
            $this->lanes->popHead($conversationId);

            return false;
        }

        if ($state === 'sending') {
            // A head stuck in `sending` is a worker that died mid-send. Re-sending
            // blind risks a double delivery; the SS-04 reconciliation drill (step 9)
            // owns deciding sent-vs-retry from adapter truth. Until reconciled, the
            // lane holds — out-of-order prevention outranks throughput (FR-15).
            return false;
        }

        if ($state === 'failed_retryable') {
            $error = $message->delivery_error ?? [];
            $nextRaw = $error['next_attempt_at'] ?? null;
            $firstRaw = $error['first_failed_at'] ?? null;
            $nextAttemptAt = is_string($nextRaw) ? CarbonImmutable::parse($nextRaw) : $now;
            $firstFailedAt = is_string($firstRaw) ? CarbonImmutable::parse($firstRaw) : $now;

            if ($now->lessThan($nextAttemptAt)) {
                if ($now->diffInSeconds($firstFailedAt, true) > self::HEAD_BLOCK_CAP_SECONDS) {
                    $this->laneSkip($conversation, $message);
                }

                return false; // the head blocks the lane (out-of-order prevention)
            }
        }

        // FR-16: over-limit waits in queued — not this cycle, nothing changes.
        if (! $this->rateGate->allowSend($conversation->channel, $conversation->channel_ref_id, $now)) {
            return false;
        }

        $this->attemptSend($conversation, $message, $now);

        return true;
    }

    private function attemptSend(Conversation $conversation, Message $message, CarbonImmutable $now): void
    {
        $priorError = $message->delivery_error ?? [];

        $this->machine->transition($message, DeliveryState::Sending, $priorError === [] ? null : $priorError);

        $adapter = $this->registry->adapter($conversation->channel);

        $result = $adapter->send(new OutboundMessage(
            externalChatId: $conversation->external_chat_id,
            payload: DeliverablePayload::text((string) $message->body),
            idempotencyKey: $message->client_idempotency_key,
        ));

        if ($result->state === SendState::Sent) {
            if (is_string($result->externalMessageId)) {
                $this->messages->recordExternalMessageId($message, $result->externalMessageId);
            }

            $this->machine->transition($message, DeliveryState::Sent);
            $this->lanes->popHead($conversation->identifier());

            // FS-01: first success after a down transition announces recovery.
            $this->health->observeSuccess($conversation->channel, $conversation->channel_ref_id);

            return;
        }

        $error = $result->error;

        // FS-01: channel-class failures feed the health streak (transition-only
        // announcements; see ChannelHealthTracker for which classes count).
        $this->health->observeFailure($conversation->channel, $conversation->channel_ref_id, $error?->reason);
        $reason = $error?->reason->value ?? 'unknown';
        $priorAttempts = $priorError['attempts'] ?? 0;
        $attempts = (is_int($priorAttempts) ? $priorAttempts : 0) + 1;

        // FR-14: non-retryable classes go final IMMEDIATELY with their reason code.
        if ($error === null || ! $error->retryable() || $attempts >= DeliveryStateMachine::MAX_SEND_ATTEMPTS) {
            $this->machine->transition($message, DeliveryState::FailedFinal, [
                'reason' => $reason,
                'attempts' => $attempts,
            ], $reason);
            $this->lanes->popHead($conversation->identifier());

            return;
        }

        $backoff = DeliveryStateMachine::BACKOFF_SECONDS[$attempts - 1];

        $this->machine->transition($message, DeliveryState::FailedRetryable, [
            'reason' => $reason,
            'attempts' => $attempts,
            'first_failed_at' => is_string($priorError['first_failed_at'] ?? null)
                ? $priorError['first_failed_at']
                : $now->toIso8601String(),
            'next_attempt_at' => $now->addSeconds($backoff)->toIso8601String(),
        ]);
        // The head stays: that IS the lane block.
    }

    /** FR-15's cap: demote the stuck head, warn loudly, drain the lane past it. */
    private function laneSkip(Conversation $conversation, Message $message): void
    {
        DB::transaction(function () use ($conversation, $message): void {
            $this->events->publish('conversation.lane_skipped', [
                'tenant_id' => $conversation->getAttribute('tenant_id'),
                'conversation_id' => $conversation->identifier(),
                'message_id' => $message->identifier(),
            ]);

            $this->audit->record(
                actionKey: 'conversation.lane_skipped',
                entity: 'conversation',
                entityId: $conversation->identifier(),
                actorType: AuditActor::System,
                payload: ['conversation_id' => $conversation->identifier(), 'message_id' => $message->identifier()],
            );
        });

        $this->lanes->demoteHead($conversation->identifier());
    }
}
