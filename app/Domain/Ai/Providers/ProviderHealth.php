<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Support\Cache\TenantCache;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * FS-01/T-01's "evented ladder": ai.provider.health_changed (the rule-16
 * amendment filed and approved at the step-8 stop) — TENANT-scoped by
 * construction (the outbox is), TRANSITION-ONLY, tri-state (the A-22
 * shape): healthy (a call succeeded) | degraded (a provider tripped, the
 * chain still has a live hop) | down (all exhausted → the caller's
 * bypass-to-handoff). The last-known state lives in the tenant cache with
 * a TTL (C-07); a transition publishes, a repeat does not.
 */
final class ProviderHealth
{
    private const STATE_TTL_SECONDS = 86_400;

    public function __construct(
        private readonly TenantCache $cache,
        private readonly EventPublisher $events,
        private readonly TenantContext $context,
    ) {}

    /** @param 'healthy'|'degraded'|'down' $status */
    public function observe(string $provider, string $status): void
    {
        $key = 'ai:provider-health:'.$provider;
        $previous = $this->cache->get($key);

        if ($previous === $status) {
            return;
        }

        $this->cache->put($key, $status, self::STATE_TTL_SECONDS, 'ai.provider.health_changed (next transition overwrites)');

        $publish = fn () => $this->events->publish('ai.provider.health_changed', [
            'tenant_id' => $this->context->tenantId(),
            'provider' => $provider,
            'status' => $status,
        ]);

        DB::transactionLevel() > 0 ? $publish() : DB::transaction($publish);
    }

    public function current(string $provider): ?string
    {
        $state = $this->cache->get('ai:provider-health:'.$provider);

        return is_string($state) ? $state : null;
    }
}
