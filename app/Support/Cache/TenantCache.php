<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * The C-07 cache conventions, as an API instead of a discipline (Doc 01 T-09).
 *
 * C-07 makes four promises: every cache key is prefixed `t:{tenant}:`, every entry has a
 * mandatory TTL, cache is never the source of truth, and every cached aggregate documents its
 * invalidation event. Three of the four can be enforced by this class's signatures — the prefix
 * is built here from the bound context and cannot be supplied, the TTL is a required positive
 * integer, and the invalidation event is a required argument at every write. The fourth
 * ("never the source of truth") is a property of the call sites, which is why the only read
 * path worth offering is read-through: remember() takes the loader that IS the source of truth.
 *
 * WHY `invalidatedBy` IS A STRING ARGUMENT AND NOT A DOC COMMENT. C-07 says "every cached
 * aggregate documents its invalidation event". A comment satisfies a reviewer once; a required
 * argument confronts every future call site, is greppable as data, and lets a test assert that
 * no write happens without naming the event that clears it. The string is the name of a domain
 * event (see docs/EVENT-CATALOG.md) or a well-known lifecycle moment (`tenant.archived`).
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * THE ONE EXCEPTION: PRE-CONTEXT CREDENTIAL MAPS.
 *
 * TenantResolver caches credential→tenant lookups (NFR-02's ≤1 ms budget). That cache is
 * read BEFORE any tenant is bound — its entire purpose is to find out which tenant to bind —
 * so it structurally cannot carry a `t:{tenant}:` prefix. Rather than leave that one cache
 * hand-rolled (where the next reader cannot tell a principled exception from a forgotten
 * convention), it lives here behind the preContext*() methods, which accept only keys in the
 * reserved `t:keymap:` namespace and only string values. Anything else asking for an
 * unprefixed key is refused. One class, one visible exception, zero ambient ones.
 * ────────────────────────────────────────────────────────────────────────────────
 */
final class TenantCache
{
    private const PRE_CONTEXT_NAMESPACE = 't:keymap:';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Read-through: return the cached value, or load, store and return it.
     *
     * @template T
     *
     * @param  Closure(): T  $loader  the source of truth this entry is a copy of
     * @return T
     */
    public function remember(string $suffix, int $ttlSeconds, string $invalidatedBy, Closure $loader): mixed
    {
        $key = $this->key($suffix);
        $this->assertTtl($ttlSeconds);
        $this->assertInvalidationEvent($invalidatedBy);

        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $value = $loader();
        Cache::put($key, $value, $ttlSeconds);

        return $value;
    }

    public function get(string $suffix): mixed
    {
        return Cache::get($this->key($suffix));
    }

    public function put(string $suffix, mixed $value, int $ttlSeconds, string $invalidatedBy): void
    {
        $key = $this->key($suffix);
        $this->assertTtl($ttlSeconds);
        $this->assertInvalidationEvent($invalidatedBy);

        Cache::put($key, $value, $ttlSeconds);
    }

    public function forget(string $suffix): void
    {
        Cache::forget($this->key($suffix));
    }

    /**
     * Atomically add to a counter, creating it WITH its TTL on first touch.
     *
     * Cache::increment() alone creates a key with no expiry — a C-07 violation by
     * accident — so creation goes through Cache::add(0, ttl) first: set-if-absent with
     * the TTL attached, atomic across workers, and increment() preserves the expiry.
     *
     * @return int the counter's value after this addition
     */
    public function increment(string $suffix, int $by, int $ttlSeconds, string $invalidatedBy): int
    {
        $key = $this->key($suffix);
        $this->assertTtl($ttlSeconds);
        $this->assertInvalidationEvent($invalidatedBy);

        Cache::add($key, 0, $ttlSeconds);

        return (int) Cache::increment($key, $by);
    }

    /** The compensation half of increment() — a refused attempt must not consume. */
    public function decrement(string $suffix, int $by): void
    {
        Cache::decrement($this->key($suffix), $by);
    }

    /** @see the class docblock — resolver credential maps only. */
    public function preContextGet(string $key): ?string
    {
        $this->assertPreContextKey($key);

        $value = Cache::get($key);

        return is_string($value) ? $value : null;
    }

    public function preContextPut(string $key, string $tenantId, int $ttlSeconds): void
    {
        $this->assertPreContextKey($key);
        $this->assertTtl($ttlSeconds);

        Cache::put($key, $tenantId, $ttlSeconds);
    }

    public function preContextForget(string $key): void
    {
        $this->assertPreContextKey($key);

        Cache::forget($key);
    }

    /**
     * Build the real key. Throws MissingTenantContext when no tenant is bound — an
     * unbound cache write has no tenant to belong to, and a shared fallback prefix
     * would be a cross-tenant cache by construction.
     */
    private function key(string $suffix): string
    {
        if ($suffix === '' || str_starts_with($suffix, 't:')) {
            throw new InvalidArgumentException(
                'Cache suffixes must be non-empty and unprefixed — the t:{tenant}: prefix is '
                .'built by TenantCache from the bound context (C-07), never supplied.',
            );
        }

        return sprintf('t:%s:%s', $this->context->tenantId(), $suffix);
    }

    private function assertTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException(
                'Every cache entry has a mandatory positive TTL (C-07); got '.$ttlSeconds.'.',
            );
        }
    }

    private function assertInvalidationEvent(string $invalidatedBy): void
    {
        if (trim($invalidatedBy) === '') {
            throw new InvalidArgumentException(
                'Every cached aggregate documents its invalidation event (C-07) — pass the '
                .'domain event or lifecycle moment that clears this entry.',
            );
        }
    }

    private function assertPreContextKey(string $key): void
    {
        if (! str_starts_with($key, self::PRE_CONTEXT_NAMESPACE)) {
            throw new InvalidArgumentException(sprintf(
                'Pre-context cache keys are reserved for credential→tenant maps and must live '
                .'under "%s" (see TenantCache — this is the one exception to t:{tenant}: prefixing).',
                self::PRE_CONTEXT_NAMESPACE,
            ));
        }
    }
}
