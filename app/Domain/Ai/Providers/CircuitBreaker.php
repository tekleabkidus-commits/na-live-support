<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use Illuminate\Support\Facades\Cache;

/**
 * T-01's per-provider circuit breaker — PLATFORM scope deliberately (an
 * outage is not tenant-shaped; C-07's tenant-prefix rule governs tenant
 * data, and this is infrastructure state with mandatory TTLs).
 *
 * Error-rate trip: `threshold` failures inside `window_s` opens the breaker
 * for `cooldown_s`; open → the fallback chain skips the provider (NFR-04's
 * <=10 s failover is the window+threshold arithmetic at step 8's drill).
 */
final class CircuitBreaker
{
    public function recordFailure(string $provider): void
    {
        $key = "ai:breaker:{$provider}:failures";

        // add() arms the window TTL exactly once; increment() rides it.
        Cache::add($key, 0, $this->intConfig('ai.breaker.window_s', 60));
        $incremented = Cache::increment($key);
        $failures = is_int($incremented) ? $incremented : 0;

        if ($failures >= $this->intConfig('ai.breaker.threshold', 5)) {
            Cache::put("ai:breaker:{$provider}:open", true, $this->intConfig('ai.breaker.cooldown_s', 30));
        }
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    public function recordSuccess(string $provider): void
    {
        Cache::forget("ai:breaker:{$provider}:failures");
    }

    public function isOpen(string $provider): bool
    {
        return (bool) Cache::get("ai:breaker:{$provider}:open", false);
    }
}
