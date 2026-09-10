<?php

declare(strict_types=1);

namespace App\Domain\Sms\Failover;

use App\Domain\Sms\Gateways\Drivers\DriverRegistry;
use App\Domain\Sms\Gateways\GatewayConfig;
use App\Domain\Sms\Gateways\GatewayCredentials;
use App\Domain\Sms\Routing\RoutingTable;
use App\Models\SmsGateway;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Doc 14 FR-09 / NFR-05 / FS-01 / FS-02 / EC-12 / SEC-04 — the per-gateway
 * circuit breaker. Consecutive GATEWAY-LEVEL failures (auth, unreachable,
 * throttled-hard — `SendResult::gatewayLevel`) within the window trip the
 * circuit: the row's `circuit_state` → open, `health` → down (the DB stamps
 * `circuit_opened_at` — NFR-05's "≤ 30 s from trip" is measured from that
 * instant); every route through the gateway fails over at the NEXT resolve
 * (RoutingTable marks the hop non-viable) — failover engages immediately.
 * Half-open probing: after the cooldown a sweep (`sms:circuit-probe`) moves
 * open → half_open; the driver's health probe (or the next real send) closes
 * it on success or re-opens it on failure. A manual reset is SEC-04's
 * high-visibility door (`sms.gateways.manage`, reason required). The alert
 * names the gateway and the error class (FS-01) — the `sms.gateway.health_changed`
 * event rides from step 5; the audit + log carry it today.
 *
 * Counters live on redis-volatile (an eviction resets the count — the safe
 * direction: a breaker that forgets a failure trips later, never earlier);
 * the circuit STATE is the row (source of truth).
 */
final class CircuitBreaker
{
    private const CONNECTION = 'volatile';

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly DriverRegistry $drivers,
        private readonly GatewayCredentials $credentials,
        private readonly RoutingTable $table,
        private readonly EventPublisher $events,
    ) {}

    /** A gateway-level failure: count it; trip on the threshold. Returns true when the circuit opened now. */
    public function recordFailure(SmsGateway $gateway, string $errorClass): bool
    {
        $key = $this->failuresKey($gateway->identifier());
        $incremented = $this->redis->connection(self::CONNECTION)->command('incr', [$key]);
        $count = is_numeric($incremented) ? (int) $incremented : 1;
        $this->redis->connection(self::CONNECTION)->command('expire', [$key, $this->windowSeconds()]);

        if ($gateway->circuit_state === 'half_open') {
            $this->transition($gateway, 'open', 'down', 'half_open_probe_failed:'.$errorClass);

            return true;
        }
        if ($gateway->circuit_state === 'closed' && $count >= $this->tripAfter()) {
            $this->transition($gateway, 'open', 'down', $errorClass);

            return true;
        }
        if ($gateway->circuit_state === 'closed' && $count >= 2 && $gateway->health === 'healthy') {
            DB::transaction(function () use ($gateway): void {
                DB::table('sms_gateways')->where('id', $gateway->getKey())->update(['health' => 'degraded', 'updated_at' => DB::raw('clock_timestamp()')]);
                $this->publishHealthChanged($gateway, 'healthy', 'degraded', $gateway->circuit_state);
            });
        }

        return false;
    }

    /** A successful send: the counter clears; a half-open circuit closes; a degraded gateway heals. */
    public function recordSuccess(SmsGateway $gateway): void
    {
        $this->redis->connection(self::CONNECTION)->command('del', [$this->failuresKey($gateway->identifier())]);
        if ($gateway->circuit_state === 'half_open') {
            $this->transition($gateway, 'closed', 'healthy', 'probe_succeeded');
        } elseif ($gateway->health === 'degraded' && $gateway->circuit_state === 'closed') {
            DB::transaction(function () use ($gateway): void {
                DB::table('sms_gateways')->where('id', $gateway->getKey())->update(['health' => 'healthy', 'updated_at' => DB::raw('clock_timestamp()')]);
                $this->publishHealthChanged($gateway, 'degraded', 'healthy', $gateway->circuit_state);
            });
        }
    }

    /**
     * The sweep: every OPEN circuit past its cooldown (DB time, from `circuit_opened_at`) becomes
     * half_open; the driver's health probe closes or re-opens it. `now` is an input (§8).
     *
     * @return array{half_opened: int, closed: int, reopened: int}
     */
    public function probe(DateTimeImmutable $now): array
    {
        $counts = ['half_opened' => 0, 'closed' => 0, 'reopened' => 0];
        foreach (SmsGateway::query()->where('circuit_state', 'open')->whereNull('deleted_at')->get() as $gateway) {
            $openedAt = $gateway->circuit_opened_at;
            if ($openedAt === null || $now->getTimestamp() - $openedAt->getTimestamp() < $this->halfOpenAfter()) {
                continue;
            }
            $this->transition($gateway, 'half_open', 'degraded', 'cooldown_elapsed');
            $counts['half_opened']++;
            $gateway->refresh();
            $config = GatewayConfig::fromStored($gateway->config);
            $healthy = $this->drivers->for($gateway)->healthy($config, $this->credentials->vendorCredential($gateway));
            if ($config->healthProbePath() === null && $gateway->driver !== 'smpp_sidecar') {
                continue; // no probe: the next real send is the probe (recordSuccess / recordFailure decide)
            }
            if ($healthy) {
                $this->transition($gateway, 'closed', 'healthy', 'probe_succeeded');
                $counts['closed']++;
            } else {
                $this->transition($gateway, 'open', 'down', 'probe_failed');
                $counts['reopened']++;
            }
        }

        return $counts;
    }

    /** SEC-04: the manual reset — high-visibility, reason required, audited. */
    public function reset(SmsGateway $gateway, string $actorId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('a manual circuit reset carries a reason (SEC-04)');
        }
        $this->redis->connection(self::CONNECTION)->command('del', [$this->failuresKey($gateway->identifier())]);
        DB::transaction(function () use ($gateway, $actorId, $reason): void {
            DB::table('sms_gateways')->where('id', $gateway->getKey())->update(['circuit_state' => 'closed', 'health' => 'healthy', 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->audit->record('sms.gateway.circuit_reset', 'sms_gateway', $gateway->identifier(), AuditActor::Staff, ['gateway_id' => $gateway->identifier(), 'reason' => $reason], $actorId);
            if ($gateway->health !== 'healthy') {
                $this->publishHealthChanged($gateway, $gateway->health, 'healthy', 'closed');
            }
        });
        $this->table->invalidate();
    }

    public function failureCount(SmsGateway $gateway): int
    {
        $raw = $this->redis->connection(self::CONNECTION)->command('get', [$this->failuresKey($gateway->identifier())]);

        return is_numeric($raw) ? (int) $raw : 0;
    }

    private function transition(SmsGateway $gateway, string $state, string $health, string $reason): void
    {
        DB::transaction(function () use ($gateway, $state, $health, $reason): void {
            DB::table('sms_gateways')->where('id', $gateway->getKey())->update(['circuit_state' => $state, 'health' => $health, 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->audit->record('sms.gateway.circuit_changed', 'sms_gateway', $gateway->identifier(), AuditActor::System, ['gateway_id' => $gateway->identifier(), 'circuit_state' => $state, 'reason' => $reason]);
            if ($gateway->health !== $health) {
                $this->publishHealthChanged($gateway, $gateway->health, $health, $state);
            }
        });
        $this->table->invalidate(); // the next resolve on every worker sees the new viability
        // FS-01: the alert names the gateway and the error class (the notification category delivers from step 5).
        Log::warning('sms gateway circuit changed', ['gateway_id' => $gateway->identifier(), 'circuit_state' => $state, 'health' => $health, 'reason' => $reason, 'metric' => 'sms.gateway.circuit_changed']);
    }

    /** The missing-DLR-rate signal (FR-09's quiet half): a healthy gateway degrades TRANSITION-ONLY; an already-degraded or open one is left as it stands. */
    public function degradeForMissingDlr(SmsGateway $gateway, float $rate): void
    {
        if ($gateway->health !== 'healthy' || $gateway->circuit_state !== 'closed') {
            return;
        }
        DB::transaction(function () use ($gateway, $rate): void {
            DB::table('sms_gateways')->where('id', $gateway->getKey())->update(['health' => 'degraded', 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->audit->record('sms.gateway.circuit_changed', 'sms_gateway', $gateway->identifier(), AuditActor::System, ['gateway_id' => $gateway->identifier(), 'circuit_state' => $gateway->circuit_state, 'reason' => sprintf('missing_dlr_rate:%.3f', $rate)]);
            $this->publishHealthChanged($gateway, 'healthy', 'degraded', $gateway->circuit_state);
        });
    }

    /** The A-22 shape: TRANSITION-ONLY — the callers guard on an actual health change. */
    private function publishHealthChanged(SmsGateway $gateway, string $old, string $new, string $circuitState): void
    {
        $this->events->publish('sms.gateway.health_changed', [
            'tenant_id' => $gateway->tenant_id,
            'gateway_id' => $gateway->identifier(),
            'old_health' => $old,
            'new_health' => $new,
            'circuit_state' => $circuitState,
        ]);
    }

    private function failuresKey(string $gatewayId): string
    {
        return sprintf('t:%s:sms:circuit:%s:failures', $this->context->tenantId(), $gatewayId);
    }

    private function tripAfter(): int
    {
        $v = config('sms.circuit.trip_after_failures');

        return is_int($v) && $v > 0 ? $v : 5;
    }

    private function windowSeconds(): int
    {
        $v = config('sms.circuit.failure_window_s');

        return is_int($v) && $v > 0 ? $v : 60;
    }

    private function halfOpenAfter(): int
    {
        $v = config('sms.circuit.half_open_after_s');

        return is_int($v) && $v > 0 ? $v : 30;
    }
}
