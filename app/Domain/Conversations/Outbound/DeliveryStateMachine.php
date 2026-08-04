<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Outbound;

use App\Domain\Conversations\Storage\MessageRepository;
use App\Models\Message;
use App\Support\Events\EventPublisher;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FR-13's delivery state machine — a FROZEN PUBLIC CONTRACT (§21) for Docs
 * 05/07/13. The transition table below IS the diagram; every edge in it is legal,
 * everything else throws IllegalDeliveryTransition (AC-02's illegal half).
 *
 *   queued → sending → sent → delivered
 *      │        │        └ (no receipt support) → sent is terminal-success
 *      │        └→ failed_retryable → backoff ×5 (1s,5s,30s,2m,10m) → failed_final
 *      └→ canceled (PRE-SEND ONLY — EC-04's compose-then-blocked case)
 *   failed_final → queued (manual retry, gated tickets.reply at its route)
 *
 * THE EDGE THAT DOES NOT EXIST is the load-bearing one: sent never rolls back
 * (EC-05). A late channel invalidation is recorded by annotatePostSendFailed() —
 * an annotation on the sent row, not a transition — because `sent` is a statement
 * about what we knew when we knew it, and history does not get rewritten.
 *
 * Events ride the transitions in the SAME transaction (C-06): sent/delivered/
 * canceled emit their FR-26 event; failed_final emits `message.failed` with its
 * reason code. failed_retryable is deliberately event-silent — it is retry
 * mechanics, not an outcome; the outcome is sent or failed_final, and emitting
 * five interim failures would make every subscriber re-derive "is this final?"
 * (realtime's transient states are FR-25 channel payloads, step 8's wiring).
 * A manual retry re-emits `message.queued`: the row genuinely re-entered the
 * queue and Doc 06/07 subscribers see it move.
 *
 * State WRITES go through MessageRepository (T-01/R-05: repositories are the
 * only path to the partitioned table); this class owns only legality + events.
 */
final class DeliveryStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'queued' => ['sending', 'canceled'],
        'sending' => ['sent', 'failed_retryable', 'failed_final'],
        'failed_retryable' => ['sending', 'failed_final'],
        'sent' => ['delivered'],
        'delivered' => [],
        'failed_final' => ['queued'],
        'canceled' => [],
    ];

    /** FR-13's schedule, seconds: 1s, 5s, 30s, 2m, 10m. */
    public const BACKOFF_SECONDS = [1, 5, 30, 120, 600];

    /** Attempts before failed(final) — the ×5 in FR-13. */
    public const MAX_SEND_ATTEMPTS = 5;

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly EventPublisher $events,
    ) {}

    /**
     * Move an outbound message along one legal edge, with its event, atomically.
     *
     * @param  array<string, mixed>|null  $deliveryError  replaces delivery_error when given
     */
    public function transition(Message $message, DeliveryState $to, ?array $deliveryError = null, ?string $reason = null): Message
    {
        if ($message->direction !== 'out') {
            throw new DomainException('only outbound messages have a delivery lifecycle (FR-13); inbound rows carry none by CHECK constraint.');
        }

        $from = (string) $message->delivery_state;

        if (! in_array($to->value, self::TRANSITIONS[$from] ?? [], true)) {
            throw IllegalDeliveryTransition::make($from, $to->value);
        }

        return DB::transaction(function () use ($message, $to, $deliveryError, $reason): Message {
            $this->messages->updateDeliveryState($message, $to->value, $deliveryError);

            $payload = [
                'tenant_id' => $message->getAttribute('tenant_id'),
                'message_id' => $message->identifier(),
                'conversation_id' => $message->conversation_id,
            ];

            match ($to) {
                DeliveryState::Sent => $this->events->publish('message.sent', $payload),
                DeliveryState::Delivered => $this->events->publish('message.delivered', $payload),
                DeliveryState::FailedFinal => $this->events->publish('message.failed', $payload + ['reason' => $reason ?? 'unknown']),
                DeliveryState::Canceled => $this->events->publish('message.canceled', $payload + ['reason' => $reason ?? 'unknown']),
                DeliveryState::Queued => $this->events->publish('message.queued', $payload),
                DeliveryState::Sending, DeliveryState::FailedRetryable => null,
            };

            return $message;
        });
    }

    /**
     * EC-05: a channel invalidating a message AFTER `sent` becomes an annotation,
     * never a rollback — there is no sent→failed edge to take, by construction.
     *
     * @param  array<string, mixed>  $detail
     */
    public function annotatePostSendFailed(Message $message, array $detail): Message
    {
        $state = (string) $message->delivery_state;

        if (! in_array($state, ['sent', 'delivered'], true)) {
            throw new DomainException(sprintf(
                'post_send_failed annotates a SENT message (EC-05); this row is "%s" — use the state machine for pre-send outcomes.',
                $state,
            ));
        }

        $error = $message->delivery_error ?? [];
        $error['post_send_failed'] = $detail;

        $this->messages->updateDeliveryState($message, $state, $error);

        return $message;
    }
}
