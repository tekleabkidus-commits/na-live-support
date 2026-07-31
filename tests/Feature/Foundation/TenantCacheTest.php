<?php

declare(strict_types=1);

use App\Support\Cache\TenantCache;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * T-09 / C-07 — the cache conventions are enforced by the API, not by review.
 *
 * Four promises: t:{tenant}: prefix, mandatory TTL, cache never the source of truth,
 * documented invalidation event per aggregate. The first, second and fourth are refusals
 * this suite triggers; the third is proven by showing a flushed cache reloads from the
 * loader, i.e. losing the cache loses nothing.
 */
beforeEach(function (): void {
    $this->cache = app(TenantCache::class);
});

it('prefixes every key with the bound tenant, so two tenants cannot share an entry', function () {
    $a = createTenant();

    $calls = 0;
    $loader = function () use (&$calls): string {
        $calls++;

        return 'value-for-call-'.$calls;
    };

    expect($this->cache->remember('agg:test', 60, 'test.invalidated', $loader))->toBe('value-for-call-1')
        // Same tenant, same suffix: served from cache, loader untouched.
        ->and($this->cache->remember('agg:test', 60, 'test.invalidated', $loader))->toBe('value-for-call-1');

    // The raw key really is t:{tenant}:{suffix} — asserted against the store directly,
    // because the prefix IS the isolation and must not be an internal detail a refactor
    // can drop while this suite stays green.
    expect(Cache::get(sprintf('t:%s:agg:test', $a->tenantId())))->toBe('value-for-call-1');

    // A different tenant with the identical suffix misses and loads its own copy.
    $b = createTenant();
    expect($this->cache->remember('agg:test', 60, 'test.invalidated', $loader))->toBe('value-for-call-2')
        ->and($calls)->toBe(2);

    // And neither tenant's entry was overwritten by the other's.
    expect(Cache::get(sprintf('t:%s:agg:test', $a->tenantId())))->toBe('value-for-call-1')
        ->and(Cache::get(sprintf('t:%s:agg:test', $b->tenantId())))->toBe('value-for-call-2');
});

it('is never the source of truth: a lost entry reloads from the loader', function () {
    createTenant();

    $this->cache->put('agg:reload', 'original', 60, 'test.invalidated');
    $this->cache->forget('agg:reload'); // the "Redis died" case, in miniature (FS-01 posture)

    expect($this->cache->get('agg:reload'))->toBeNull()
        ->and($this->cache->remember('agg:reload', 60, 'test.invalidated', fn (): string => 'reloaded'))
        ->toBe('reloaded');
});

it('refuses a write with no TTL worth the name (C-07: TTL mandatory)', function (int $ttl) {
    createTenant();

    expect(fn () => $this->cache->put('agg:ttl', 'x', $ttl, 'test.invalidated'))
        ->toThrow(InvalidArgumentException::class, 'mandatory positive TTL');
})->with([0, -1, -3600]);

it('refuses a write that does not name its invalidation event (C-07: documented invalidation)', function () {
    createTenant();

    expect(fn () => $this->cache->put('agg:noevent', 'x', 60, '   '))
        ->toThrow(InvalidArgumentException::class, 'invalidation event');
});

it('refuses a suffix that tries to smuggle its own prefix', function () {
    createTenant();

    expect(fn () => $this->cache->get('t:other-tenant:secret'))
        ->toThrow(InvalidArgumentException::class, 'never supplied');
});

it('throws MissingTenantContext when no tenant is bound — there is no shared fallback prefix', function () {
    app(TenantContext::class)->clear();

    expect(fn () => $this->cache->remember('agg:unbound', 60, 'test.invalidated', fn (): string => 'x'))
        ->toThrow(MissingTenantContext::class);
});

it('confines the pre-context exception to the t:keymap: namespace', function () {
    // The credential→tenant map is the one cache that cannot be tenant-prefixed — it is
    // read to find out WHICH tenant. It works unbound, in its reserved namespace only.
    app(TenantContext::class)->clear();

    $this->cache->preContextPut('t:keymap:test:pref:abc123', 'some-tenant-id', 60);
    expect($this->cache->preContextGet('t:keymap:test:pref:abc123'))->toBe('some-tenant-id');

    $this->cache->preContextForget('t:keymap:test:pref:abc123');
    expect($this->cache->preContextGet('t:keymap:test:pref:abc123'))->toBeNull();

    // Any attempt to use the escape hatch for an ordinary key is refused.
    expect(fn () => $this->cache->preContextPut('t:settings:whatever', 'x', 60))
        ->toThrow(InvalidArgumentException::class, 'reserved for credential→tenant maps');
});
