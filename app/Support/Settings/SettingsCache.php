<?php

declare(strict_types=1);

namespace App\Support\Settings;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The settings read cache (Doc 01 T-04, NFR-03, FS-01; C-07).
 *
 * One Redis hash per tenant, `t:{tenant}:settings`, on the VOLATILE instance — settings
 * are reconstructible from the database, so they belong on the `allkeys-lru`
 * no-persistence side of the two-Redis split (CLAUDE.md §3). Losing this hash costs
 * latency, never data.
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * FS-01 IS THE INTERESTING PART: THIS CLASS MUST NOT BE ABLE TO FAIL A REQUEST.
 *
 * "Redis unavailable: settings fall back to DB reads (degraded latency, logged); never
 * failed."
 *
 * Settings are read on essentially every request, so if a settings read can throw, a
 * Redis blip becomes a total outage. Every Redis call here is therefore wrapped: on
 * failure we log once and read the database, which is the source of truth anyway (C-07 —
 * the cache is never the source of truth).
 *
 * A blanket `catch (Throwable)` around cache I/O is normally a smell. Here it is the
 * requirement: the set of ways a network round trip can fail is open-ended, and the
 * correct response to every one of them is identical.
 *
 * WHAT IS *NOT* SWALLOWED: failures from the fallback itself, and any error in decoding a
 * value. A corrupt setting must surface, because applying a default in place of an
 * operator's real configuration is exactly the silent-wrong-behaviour this project keeps
 * refusing to ship.
 * ────────────────────────────────────────────────────────────────────────────────
 *
 * C-07 compliance: every key is prefixed `t:{tenant}:`, every entry has a mandatory TTL,
 * and the invalidation event is documented — `tenant.setting_changed`, published by
 * SettingsRepository, which also calls forget() directly for the local node. The TTL is
 * the backstop that bounds NFR-03's ≤2 s cross-node propagation if a `forget` is ever
 * lost.
 */
final class SettingsCache
{
    /**
     * Short, because it is a backstop rather than the primary invalidation.
     *
     * Writes call forget() directly, so the TTL only matters if that eviction is lost —
     * another node, a dropped connection. NFR-03 requires propagation within 2 s across
     * nodes; 60 s is far longer, so this TTL is NOT how that requirement is met (the
     * event is), it is how a permanently-stale entry becomes impossible.
     */
    private const TTL_SECONDS = 60;

    /**
     * Read the tenant's overrides, populating the cache from $loader on a miss.
     *
     * @param  callable(): array<string, mixed>  $loader
     * @return array<string, mixed>
     */
    public function overrides(string $tenantId, callable $loader): array
    {
        $key = $this->key($tenantId);

        try {
            // phpredis types HGETALL as array|Redis|false (it returns the client itself in
            // pipeline mode), so this is narrowed by inspection rather than by an @var tag
            // asserting something the native signature contradicts.
            $cached = $this->connection()->hgetall($key);

            if (is_array($cached) && $cached !== []) {
                // A hash cannot hold null, so an empty override set is stored as a
                // sentinel field rather than as an empty hash — otherwise "no overrides"
                // would be indistinguishable from "cache miss" and every request for a
                // default-configured tenant would hit the database.
                if (array_key_exists('__empty__', $cached)) {
                    return [];
                }

                $decoded = [];
                foreach ($cached as $field => $raw) {
                    $decoded[(string) $field] = json_decode($raw, true);
                }

                return $decoded;
            }
        } catch (Throwable $e) {
            $this->reportDegraded('read', $e);

            return $loader();
        }

        $fresh = $loader();

        try {
            $payload = $fresh === []
                ? ['__empty__' => '1']
                : array_map(static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR), $fresh);

            $connection = $this->connection();
            $connection->hmset($key, $payload);
            $connection->expire($key, self::TTL_SECONDS);
        } catch (Throwable $e) {
            // The read already succeeded from the database; failing to cache it is not a
            // reason to fail the request.
            $this->reportDegraded('write', $e);
        }

        return $fresh;
    }

    public function forget(string $tenantId): void
    {
        try {
            $this->connection()->del($this->key($tenantId));
        } catch (Throwable $e) {
            // The TTL bounds the damage. Logged so a persistent inability to invalidate
            // is visible rather than showing up as operators reporting stale settings.
            $this->reportDegraded('invalidate', $e);
        }
    }

    /**
     * `t:{tenant}:settings` — C-07's mandatory prefix.
     */
    private function key(string $tenantId): string
    {
        return sprintf('t:%s:settings', $tenantId);
    }

    /**
     * The concrete connection, not Illuminate\Contracts\Redis\Connection.
     *
     * The contract exposes only get/set/del-shaped commands; HGETALL/HMSET/EXPIRE are on
     * the concrete class. Typing against the contract and calling hash commands anyway
     * would work at runtime (they go through __call) and be invisible to static analysis
     * — exactly the kind of "works until it doesn't" that PHPStan at max level exists to
     * stop.
     */
    private function connection(): Connection
    {
        return Redis::connection('volatile');
    }

    private function reportDegraded(string $operation, Throwable $e): void
    {
        Log::warning('settings cache degraded, falling back to database', [
            'operation' => $operation,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            // FS-01 calls this state "degraded"; naming it in the log lets Doc 18 alert
            // on it rather than on a generic Redis error.
            'degradation' => 'settings.cache_unavailable',
        ]);
    }
}
