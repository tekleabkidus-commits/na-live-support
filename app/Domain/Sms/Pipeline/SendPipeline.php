<?php

declare(strict_types=1);

namespace App\Domain\Sms\Pipeline;

use App\Domain\Sms\Catalog\Lane;
use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Cost\CostEstimate;
use App\Domain\Sms\Encoding\Segmenter;
use App\Domain\Sms\Failover\CircuitBreaker;
use App\Domain\Sms\Gateways\Drivers\DriverRegistry;
use App\Domain\Sms\Gateways\GatewayConfig;
use App\Domain\Sms\Gateways\GatewayCredentials;
use App\Domain\Sms\Gateways\RenderedMessage;
use App\Domain\Sms\Guards\GuardFunction;
use App\Domain\Sms\Guards\GuardOutcome;
use App\Domain\Sms\Rendering\RenderContext;
use App\Domain\Sms\Rendering\TemplateRenderer;
use App\Domain\Sms\Routing\RoutingTable;
use App\Domain\Sms\Shaping\GatewayTokenBucket;
use App\Domain\Sms\State\SmsDeliveryState;
use App\Domain\Sms\State\SmsDeliveryStateMachine;
use App\Domain\Sms\Support\PhoneMask;
use App\Domain\Sms\Templates\TemplateReader;
use App\Models\SmsGateway;
use App\Models\SmsMessage;
use App\Support\Timers\DueSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FR-11's send path, run by SendSmsJob: live guards → render (the published
 * version, its id recorded) → route → shape (WAIT, never drop) → driver.send
 * → `sent` + provider_id + the FR-19 cost accrual — or the honest exits:
 * retryable ×4 with backoff, failover ONCE per fallback per run, `failed{reason}`.
 * Every hop lands on `attempts`; every guard verdict on `guard_log`; the whole
 * thing is idempotent on the row's state (a re-driven job on a terminal row
 * returns without a write). Log lines carry the MASKED phone only.
 */
final class SendPipeline
{
    public const RETRY_ROUNDS = 4;

    private const BACKOFF_BASE_SECONDS = 10;

    public function __construct(
        private readonly LiveFacts $facts,
        private readonly GuardFunction $guard,
        private readonly SmsLifecycle $lifecycle,
        private readonly TemplateReader $templates,
        private readonly TemplateRenderer $renderer,
        private readonly Segmenter $segmenter,
        private readonly RoutingTable $routes,
        private readonly GatewayTokenBucket $bucket,
        private readonly DriverRegistry $drivers,
        private readonly GatewayCredentials $credentials,
        private readonly CircuitBreaker $breaker,
        private readonly SmsDeliveryStateMachine $machine,
        private readonly DueSet $timers,
    ) {}

    /** @return int 0 = done; N > 0 = release and retry in N seconds */
    public function run(string $smsMessageId): int
    {
        $row = SmsMessage::query()->find($smsMessageId);
        if ($row === null || ! in_array($row->state, ['queued', 'sending'], true)) {
            return 0; // idempotent on state: held rows wait for release, terminal rows are done
        }

        // A resumed `sending` row (a prior run released mid-flight) goes back through
        // the legal edge before re-entering the path — the machine stays the law.
        if ($row->state === 'sending') {
            $this->transition($row, SmsDeliveryState::Queued);
        }

        $event = SendableEvent::from($row->event);

        if (($verdict = $this->liveGuard($row, $event)) !== null) {
            return $verdict;
        }
        if (($verdict = $this->render($row, $event)) !== null) {
            return $verdict;
        }

        return $this->routeAndSend($row);
    }

    /** EC-02: the guard runs AGAIN on send-time facts — an identity unlinked since enqueue is a skip. */
    private function liveGuard(SmsMessage $row, SendableEvent $event): ?int
    {
        $request = new SendRequest($event, $row->customer_id, $row->ticket_id, $row->conversation_id, $row->customer_id === null ? $row->phone : null);
        $livePhone = $row->customer_id === null ? $row->phone : $this->facts->phoneFor($row->customer_id);
        $decision = $this->guard->evaluate($this->facts->input($request, $livePhone, $row->identifier()));
        $this->appendGuardLog($row, $decision->log);

        if ($decision->outcome === GuardOutcome::Proceed) {
            return null;
        }

        if ($decision->outcome === GuardOutcome::Hold) {
            DB::transaction(function () use ($row, $decision): void {
                $this->transition($row, SmsDeliveryState::Held);
                $this->lifecycle->publish('sms.held', $row, $decision->reason?->value, null);
            });
            $this->timers->schedule('sms_hold_release', DueSet::member($row->tenant_id, $row->identifier()), (int) ((microtime(true) + EnqueueSms::HOLD_RECHECK_SECONDS) * 1_000_000));

            return 0;
        }

        DB::transaction(function () use ($row, $decision): void {
            $row->skip_reason = $decision->reason?->value;
            $this->transition($row, SmsDeliveryState::Skipped);
            $this->lifecycle->publish('sms.skipped', $row, $decision->reason?->value);
        });

        return 0;
    }

    /** FR-04: the PUBLISHED version renders, its id lands on the row; C-08's fallback is named (R-24). */
    private function render(SmsMessage $row, SendableEvent $event): ?int
    {
        if ($row->rendered_body !== null) {
            return null; // the OTP door rendered at enqueue (the code never rides a queue payload)
        }

        $resolved = $this->templates->resolveForSend($row->event, $row->language);
        if ($resolved === null) {
            DB::transaction(function () use ($row): void {
                $row->fail_reason = 'no_template';
                $this->transition($row, SmsDeliveryState::Failed);
                $this->lifecycle->publish('sms.failed', $row, 'no_template');
            });

            return 0;
        }

        if ($resolved['fallback_from'] !== null) {
            Log::info('sms language fallback', ['sms_message_id' => $row->identifier(), 'requested' => $resolved['fallback_from'], 'rendered' => $resolved['language'], 'to_masked' => PhoneMask::forLog($row->phone)]);
        }

        $result = $this->renderer->render($resolved['version'], $resolved['template'], new RenderContext(
            language: $resolved['language'],
            ticketId: $row->ticket_id,
        ));

        if (! $result->sends() || $result->text === null) {
            DB::transaction(function () use ($row): void {
                $row->skip_reason = 'render_missing_variable';
                $this->transition($row, SmsDeliveryState::Skipped);
                $this->lifecycle->publish('sms.skipped', $row, 'render_missing_variable');
            });

            return 0;
        }

        $segmentation = $this->segmenter->segment($result->text);
        $row->rendered_body = $result->text;
        $row->template_id = $resolved['template']->identifier();
        $row->template_version_id = $resolved['version']->identifier();
        $row->encoding = $segmentation->encoding->value;
        $row->segments = $segmentation->segments;
        $row->save();

        return null;
    }

    private function routeAndSend(SmsMessage $row): int
    {
        $resolution = $this->routes->resolve($row->phone);
        if ($resolution->noRoute) {
            DB::transaction(function () use ($row): void {
                $row->skip_reason = 'no_route';
                $this->transition($row, SmsDeliveryState::Skipped);
                $this->lifecycle->publish('sms.skipped', $row, 'no_route');
            });

            return 0;
        }

        $hops = $resolution->viable();
        if ($hops === []) {
            // a matched route whose every gateway is down: wait for the circuit sweep, never drop
            return 30;
        }

        $lane = Lane::from($row->lane);
        $lastCode = 'send_failed';

        foreach ($hops as $hop) {
            $gateway = SmsGateway::query()->find($hop['gateway_id']);
            if ($gateway === null) {
                continue;
            }
            $config = GatewayConfig::fromStored($gateway->config);

            if (! $this->bucket->allow($gateway->identifier(), $config->throughputPerSecond(), $lane, microtime(true))) {
                if ($row->state === 'sending') {
                    DB::transaction(fn () => $this->transition($row, SmsDeliveryState::Queued));
                }

                return 1; // shaping is a WAIT, never a drop (T-04)
            }

            if ($row->state === 'queued') {
                DB::transaction(function () use ($row): void {
                    $this->transition($row, SmsDeliveryState::Sending);
                });
            }

            $result = $this->drivers->for($gateway)->send($this->renderedMessage($row, $hop, $gateway), $config, $this->credentials->vendorCredential($gateway));
            $this->appendAttempt($row, $gateway->identifier(), $result->httpStatus, $result->code, $result->retryable);

            if ($result->accepted) {
                $this->accepted($row, $gateway, $config, $result->providerId);

                return 0;
            }

            $lastCode = $result->code ?? 'send_failed';

            if ($result->gatewayLevel) {
                $this->breaker->recordFailure($gateway->refresh(), $lastCode);

                continue; // failover: ONCE per fallback per run (FR-11)
            }

            // per-message, non-retryable (a 400, the vendor cap): another gateway will not fix this message
            DB::transaction(function () use ($row, $lastCode): void {
                $row->fail_reason = $lastCode;
                $this->transition($row, SmsDeliveryState::Failed);
                $this->lifecycle->publish('sms.failed', $row, $lastCode);
            });

            return 0;
        }

        return $this->exhausted($row, $lastCode);
    }

    private function accepted(SmsMessage $row, SmsGateway $gateway, GatewayConfig $config, ?string $providerId): void
    {
        $card = $config->rateCardFor($row->phone);
        DB::transaction(function () use ($row, $gateway, $card, $providerId): void {
            $row->gateway_id = $gateway->identifier();
            $row->provider_id = $providerId;
            if ($card !== null && $row->segments !== null) {
                $row->cost_amount = CostEstimate::amount($row->segments, $card);
                $row->cost_currency = $card->currency;
            }
            $this->transition($row, SmsDeliveryState::Sent);
            $this->lifecycle->publish('sms.sent', $row);
        });
        $this->breaker->recordSuccess($gateway->refresh());
        Log::info('sms sent', ['sms_message_id' => $row->identifier(), 'gateway_id' => $gateway->identifier(), 'segments' => $row->segments, 'to_masked' => PhoneMask::forLog($row->phone)]);
    }

    /** Every viable hop failed gateway-level this run: ×4 backoff rounds, then the honest terminal. */
    private function exhausted(SmsMessage $row, string $lastCode): int
    {
        /** @var list<array<string, mixed>> $attempts */
        $attempts = $row->attempts;
        $rounds = count(array_filter($attempts, static fn (array $a): bool => ($a['code'] ?? null) === 'run_exhausted'));

        if ($rounds < self::RETRY_ROUNDS) {
            $this->appendAttempt($row, null, null, 'run_exhausted', true);
            DB::transaction(fn () => $this->transition($row, SmsDeliveryState::Queued));

            return self::BACKOFF_BASE_SECONDS * (2 ** $rounds);
        }

        DB::transaction(function () use ($row, $lastCode): void {
            $row->fail_reason = $lastCode;
            $this->transition($row, SmsDeliveryState::Failed);
            $this->lifecycle->publish('sms.failed', $row, $lastCode);
        });

        return 0;
    }

    /** @param array{gateway_id: string, sender_id: ?string, circuit_open: bool} $hop */
    private function renderedMessage(SmsMessage $row, array $hop, SmsGateway $gateway): RenderedMessage
    {
        $signing = $gateway->webhook_credential_id !== null
            && DB::table('webhook_credentials')->where('id', $gateway->webhook_credential_id)->whereNotNull('hmac_secret')->exists();

        return new RenderedMessage(
            $row->identifier(),
            $row->phone,
            (string) $row->rendered_body,
            $hop['sender_id'],
            $this->credentials->callbackUrl($gateway, $signing),
        );
    }

    private function transition(SmsMessage $row, SmsDeliveryState $to): void
    {
        $row->state = $this->machine->transition(SmsDeliveryState::from($row->state), $to)->value;
        $row->save();
    }

    /** @param list<array{check: string, result: string}> $entries */
    private function appendGuardLog(SmsMessage $row, array $entries): void
    {
        /** @var list<array<string, mixed>> $log */
        $log = $row->guard_log;
        $row->guard_log = [...$log, ['check' => 'live_at_send', 'result' => 'evaluated'], ...$entries];
        $row->save();
    }

    private function appendAttempt(SmsMessage $row, ?string $gatewayId, ?int $httpStatus, ?string $code, bool $retryable): void
    {
        /** @var list<array<string, mixed>> $attempts */
        $attempts = $row->attempts;
        $row->attempts = [...$attempts, [
            'gateway_id' => $gatewayId,
            'at' => (new \DateTimeImmutable)->format(DATE_ATOM),
            'http_status' => $httpStatus,
            'code' => $code,
            'retryable' => $retryable,
        ]];
        $row->save();
    }
}
