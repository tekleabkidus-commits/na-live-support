<?php

declare(strict_types=1);

namespace App\Domain\Automation\Webhooks;

use App\Jobs\Automation\WebhookDeliveryJob;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Doc 12 FR-03 webhook-out / SEC-02 / EC-13 — the delivery machine (RIDER 5
 * table B). ONE attempt per call, from ROW TRUTH:
 *   - the destination must still be live (an admin deactivation or an earlier
 *     auto-disable ABANDONS the delivery honestly);
 *   - the payload is SIGNED with the tenant's Doc 01 secret — an EMPTY secret
 *     refuses to send at all (a signed-payloads law has no unsigned mode);
 *   - the request rides the EGRESS PROXY from config; an empty proxy value
 *     means direct egress and is refused before a byte leaves (the Doc 04
 *     SEC-03 posture, pinned by WebhookEgressTest);
 *   - the SSRF rules run again right before the send (defense in depth).
 * EC-13: attempts count on the row; after MAX_ATTEMPTS the delivery is
 * abandoned, the destination auto-disables, and `automation.webhook.disabled`
 * (the alert) publishes in the same transaction. The rule that produced the
 * delivery never waited: its arm answered `queued` and the chain continued.
 * The schedule is a code constant (BR-05's posture), not a setting.
 */
final class WebhookDeliveries
{
    public const MAX_ATTEMPTS = 8;

    /** Seconds to wait before attempt N+1 (index = attempts made so far − 1). */
    public const BACKOFF_S = [60, 300, 900, 3600, 10800, 21600, 43200];

    public function __construct(
        private readonly TenantContext $context,
        private readonly WebhookDestinations $destinations,
        private readonly SettingsRepository $settings,
        private readonly EventPublisher $events,
        private readonly HttpFactory $http,
    ) {}

    /**
     * Record + queue one delivery. Returns the delivery id.
     *
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(string $destinationId, ?string $executionId, ?string $ruleId, string $eventName, array $payload): string
    {
        $id = (string) Str::uuid7();
        DB::table('automation_webhook_deliveries')->insert([
            'id' => $id, 'tenant_id' => $this->context->tenantId(), 'destination_id' => $destinationId,
            'execution_id' => $executionId, 'rule_id' => $ruleId, 'event_name' => $eventName,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        WebhookDeliveryJob::dispatch($id)->onQueue('automation');

        return $id;
    }

    /**
     * ONE attempt. Returns delivered | retry | abandoned | skipped (already terminal).
     */
    public function attempt(string $deliveryId): string
    {
        /** @var object{id: string, destination_id: string, rule_id: ?string, event_name: string, payload: string, attempts: int, state: string}|null $delivery */
        $delivery = DB::table('automation_webhook_deliveries')->where('id', $deliveryId)->first();
        if ($delivery === null || $delivery->state !== 'queued') {
            return 'skipped';
        }
        $destination = $this->destinations->live($delivery->destination_id);
        if ($destination === null) {
            $this->finish($delivery, 'abandoned', null, 'destination_inactive');

            return 'abandoned';
        }

        $secret = $this->settings->get('tenant.outbound_webhook_secret');
        if (! is_string($secret) || $secret === '') {
            // Signed payloads have no unsigned mode: refuse, abandon, never send.
            $this->finish($delivery, 'abandoned', null, 'unsigned_refused');

            return 'abandoned';
        }
        $proxy = config('automation.webhook.egress_proxy');
        if (! is_string($proxy) || $proxy === '') {
            throw new RuntimeException('automation.webhook.egress_proxy is empty — direct egress is refused (SEC-02 / Doc 04 SEC-03 posture)');
        }
        SsrfRules::assertAllowed($destination->url);

        $body = $delivery->payload;
        $headers = WebhookSigner::headers($secret, $body, time(), (string) Str::uuid7()) + ['Content-Type' => 'application/json'];

        $statusCode = null;
        $error = null;
        try {
            $response = $this->http
                ->withOptions(['proxy' => $proxy, 'timeout' => $this->timeoutSeconds(), 'connect_timeout' => 5])
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($destination->url);
            $statusCode = $response->status();
            if (! $response->successful()) {
                $error = 'http_'.$statusCode;
            }
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
        }

        if ($error === null) {
            $this->finish($delivery, 'delivered', $statusCode, null);
            DB::table('automation_webhook_destinations')->where('id', $destination->id)->update([
                'failure_streak' => 0, 'last_delivered_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
            ]);

            return 'delivered';
        }

        $attempts = $delivery->attempts + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            // EC-13: exhausted — abandon + auto-disable + the alert, ONE transaction.
            DB::transaction(function () use ($delivery, $destination, $attempts, $statusCode, $error): void {
                DB::table('automation_webhook_deliveries')->where('id', $delivery->id)->update([
                    'attempts' => $attempts, 'state' => 'abandoned', 'last_status_code' => $statusCode,
                    'last_error' => $error, 'next_attempt_at' => null, 'updated_at' => DB::raw('clock_timestamp()'),
                ]);
                DB::table('automation_webhook_destinations')->where('id', $destination->id)->update(['failure_streak' => $destination->failure_streak + 1]);
                $this->destinations->autoDisable($destination->id, $error);
                $this->events->publish('automation.webhook.disabled', [
                    'tenant_id' => $this->context->tenantId(), 'destination_id' => $destination->id,
                    'rule_id' => $delivery->rule_id, 'delivery_id' => $delivery->id, 'attempts' => $attempts,
                ]);
            });

            return 'abandoned';
        }

        // EC-13's next instant from DB time (C-11), the backoff bound as a parameter.
        DB::statement(
            'UPDATE automation_webhook_deliveries SET attempts = ?, last_status_code = ?, last_error = ?, next_attempt_at = clock_timestamp() + make_interval(secs => ?), updated_at = clock_timestamp() WHERE id = ?',
            [$attempts, $statusCode, $error, self::delaySeconds($attempts), $delivery->id],
        );
        DB::table('automation_webhook_destinations')->where('id', $destination->id)->update([
            'failure_streak' => $destination->failure_streak + 1, 'last_error' => $error,
            'last_failed_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
        ]);

        return 'retry';
    }

    private function timeoutSeconds(): int
    {
        $timeout = config('automation.webhook.timeout_s');

        return is_int($timeout) && $timeout > 0 ? $timeout : 10;
    }

    /** The wait after `attemptsMade` failures (EC-13's backoff). */
    public static function delaySeconds(int $attemptsMade): int
    {
        return self::BACKOFF_S[min(max($attemptsMade, 1), count(self::BACKOFF_S)) - 1];
    }

    /** @param object{id: string} $delivery */
    private function finish(object $delivery, string $state, ?int $statusCode, ?string $error): void
    {
        DB::table('automation_webhook_deliveries')->where('id', $delivery->id)->update([
            'state' => $state, 'last_status_code' => $statusCode, 'last_error' => $error,
            'delivered_at' => $state === 'delivered' ? DB::raw('clock_timestamp()') : null,
            'next_attempt_at' => null, 'updated_at' => DB::raw('clock_timestamp()'),
        ] + ($state === 'delivered' ? ['attempts' => DB::raw('attempts + 1')] : []));
    }
}
