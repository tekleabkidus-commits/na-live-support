<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Outbound;

use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * SS-04's post-crash reconciliation (Doc 04; also Doc 01 AC-03's vehicle):
 * a worker killed mid-send leaves its head in `sending`, and LaneWorker
 * deliberately HOLDS that lane — re-sending blind risks a double delivery,
 * so out-of-order prevention outranks throughput until someone decides
 * sent-vs-retry from recorded truth. This class is that decision, entirely
 * inside FR-13's frozen edges:
 *
 *  - external_message_id RECORDED → the adapter accepted the send before the
 *    crash (the id is written before the sent transition) → `sending → sent`.
 *  - no external id → the send may or may not have left the building →
 *    `sending → failed_retryable` with an immediate next attempt. The retry
 *    carries the SAME client idempotency key (T-03) — that key, not optimism,
 *    is what makes the re-send safe: an adapter that already accepted the
 *    first attempt dedupes on it.
 *
 * Neither branch touches the lane: the row is still at its lane head (the
 * worker died before popHead), and the next LaneWorker cycle pops a `sent`
 * head as terminal or retries a `failed_retryable` one in order.
 *
 * `$stuckAfterSeconds` guards against reconciling a LIVE send: a row is only
 * "stuck" once it has sat in `sending` longer than any healthy send takes.
 */
final class SendingReconciler
{
    public const DEFAULT_STUCK_AFTER_SECONDS = 60;

    public function __construct(
        private readonly DeliveryStateMachine $machine,
    ) {}

    /** @return array{recovered_sent: int, requeued_retry: int} */
    public function reconcile(CarbonImmutable $now, int $stuckAfterSeconds = self::DEFAULT_STUCK_AFTER_SECONDS): array
    {
        $recovered = 0;
        $requeued = 0;

        $stuck = Message::query()
            ->where('direction', 'out')
            ->where('delivery_state', 'sending')
            ->where('updated_at', '<=', $now->subSeconds($stuckAfterSeconds))
            ->orderBy('id')
            ->get();

        foreach ($stuck as $message) {
            if (is_string($message->external_message_id) && $message->external_message_id !== '') {
                $this->machine->transition($message, DeliveryState::Sent);
                $recovered++;

                continue;
            }

            $priorError = $message->delivery_error ?? [];
            $priorAttempts = $priorError['attempts'] ?? 0;

            $this->machine->transition($message, DeliveryState::FailedRetryable, [
                'reason' => 'network_error',
                'attempts' => is_int($priorAttempts) ? $priorAttempts : 0,
                'first_failed_at' => is_string($priorError['first_failed_at'] ?? null)
                    ? $priorError['first_failed_at']
                    : $now->toIso8601String(),
                'next_attempt_at' => $now->toIso8601String(),
            ]);
            $requeued++;
        }

        if ($recovered > 0 || $requeued > 0) {
            Log::info('sending reconciliation completed', [
                'metric' => 'messaging.outbound.reconciled',
                'recovered_sent' => $recovered,
                'requeued_retry' => $requeued,
            ]);
        }

        return ['recovered_sent' => $recovered, 'requeued_retry' => $requeued];
    }
}
