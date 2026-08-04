<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Support\Auth\Tokens\TokenScopeGate;
use App\Support\Cache\TenantCache;
use App\Support\Rbac\CachedCapabilityMaps;
use App\Support\Rbac\CapabilityCacheInvalidator;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * T-02's capability cache (FR-07, NFR-01/02, SEC-05, FS-01/FS-02, R-04).
 *
 * The combined staleness bound, on the record (the plan's statement): explicit
 * invalidation busts BOTH `t:{tenant}:cap:{user}` and Doc 02's per-token
 * `tokscope:` entries, so both surfaces converge immediately on mutation;
 * with invalidation lost (FS-02), TTLs bound the session surface at 10 s and
 * the token surface at 10 s + 10 s = 20 s — the documented maximum. AC-04's
 * browser proof (step 9) drives the ≤10 s end-to-end wall-clock version.
 */
function cachedUserFixture(): array
{
    $tenant = createTenant();

    return [$tenant, ...asTenant($tenant, function () {
        $role = Role::create([
            'name' => 'Cache fixture role',
            'color' => 'accent-1',
            'permissions' => ['customers.view', 'customers.rg_flag.view'],
            'scopes' => ['customers' => 'All'],
        ]);
        $user = User::factory()->create();
        $user->forceFill(['role_id' => $role->getKey()])->save();

        return [$user, $role];
    })];
}

it('serves the map from cache within the TTL and rebuilds after explicit invalidation (T-02, FR-07)', function () {
    [$tenant, $user, $role] = cachedUserFixture();

    asTenant($tenant, function () use ($user, $role): void {
        $provider = app(CapabilityMapProvider::class);
        expect($provider)->toBeInstanceOf(CachedCapabilityMaps::class)
            ->and($provider->mapFor($user)->allows('customers.view'))->toBeTrue();

        // Change the role UNDER the cache: the stale map keeps answering —
        // that IS the 10 s contract, not a bug —
        $role->forceFill(['permissions' => []])->save();
        expect($provider->mapFor($user)->allows('customers.view'))->toBeTrue();

        // — until the mutation's invalidator call, after which the next
        // check anywhere in the cluster rebuilds fresh.
        app(CapabilityCacheInvalidator::class)->forgetUser($user);
        expect($provider->mapFor($user)->allows('customers.view'))->toBeFalse();
    });
});

it('bypasses the cache for R-04\'s highest-sensitivity keys — revocation has NO staleness window', function () {
    [$tenant, $user, $role] = cachedUserFixture();

    asTenant($tenant, function () use ($user, $role): void {
        $provider = app(CapabilityMapProvider::class);

        // Warm the cache with both grants in place.
        expect($provider->verdictFor($user, 'customers.rg_flag.view')->allowed)->toBeTrue()
            ->and($provider->verdictFor($user, 'customers.view')->allowed)->toBeTrue();

        // Revoke everything WITHOUT invalidating.
        $role->forceFill(['permissions' => []])->save();

        // The RG flag deny is visible IMMEDIATELY (bypass); the ordinary key
        // still serves the cached allow until TTL/invalidation — the contrast
        // is the proof the bypass exists.
        expect($provider->verdictFor($user, 'customers.rg_flag.view')->allowed)->toBeFalse()
            ->and($provider->verdictFor($user, 'customers.view')->allowed)->toBeTrue();
    });
});

it('pins both composed TTLs at 10 s — the FS-02 bounds are 10 s session / 20 s token, never silently wider', function () {
    expect(CachedCapabilityMaps::TTL_SECONDS)->toBe(10)
        ->and(TokenScopeGate::CACHE_TTL_SECONDS)->toBe(10)
        ->and(CachedCapabilityMaps::CACHE_BYPASS_KEYS)->toBe([
            'customers.rg_flag.view', 'customers.rg_flag.set', 'audit.view', 'audit.export',
        ]);
});

it('busts Doc 02\'s per-token scope cache in the same invalidation — the two caches converge together', function () {
    [$tenant, $user] = cachedUserFixture();

    asTenant($tenant, function () use ($user): void {
        $token = $user->tokens()->create([
            'name' => 'cache-bust fixture',
            'token' => hash('sha256', 'fixture'),
            'abilities' => ['tickets.view'],
        ]);

        // The literal suffix TokenScopeGate writes (pinned here because that
        // file must not change — the T-05 seam contract).
        $suffix = 'tokscope:'.$token->getKey();
        app(TenantCache::class)->put($suffix, ['tickets.view'], 10, 'test fixture');
        expect(app(TenantCache::class)->get($suffix))->toBe(['tickets.view']);

        app(CapabilityCacheInvalidator::class)->forgetUser($user);

        expect(app(TenantCache::class)->get($suffix))->toBeNull();
    });
});

it('keeps VERDICTS BYTE-IDENTICAL with the cache store dead, logs the metric, and stays up (FS-01)', function () {
    [$tenant, $user] = cachedUserFixture();
    $logs = &capturedLogsRef();

    asTenant($tenant, function () use ($user, &$logs): void {
        $healthy = app(CapabilityMapProvider::class)->mapFor($user);

        // Kill the store: every cache op now throws, exactly like a dead
        // volatile Redis.
        Cache::swap(new Repository(new class extends Illuminate\Cache\NullStore
        {
            public function get($key): mixed
            {
                throw new RuntimeException('cache down');
            }

            public function put($key, $value, $seconds): bool
            {
                throw new RuntimeException('cache down');
            }
        }));

        // The swap must actually kill the store — otherwise this test proves
        // nothing (positive control for the sabotage itself).
        expect(fn () => Cache::get('probe'))->toThrow(RuntimeException::class);

        $degraded = app(CapabilityMapProvider::class)->mapFor($user);

        // No security regression: the degraded answer IS the healthy answer.
        expect(serialize($degraded))->toBe(serialize($healthy));

        $metric = collect($logs)->first(
            fn (array $log) => ($log['context']['security_metric'] ?? null) === 'rbac.capability_cache_unavailable',
        );
        expect($metric)->not->toBeNull();
    });
});

it('stores PRIMITIVES ONLY — verdicts, never objects or secrets (SEC-05 + the serializable_classes=false defense)', function () {
    [$tenant, $user] = cachedUserFixture();

    asTenant($tenant, function () use ($user): void {
        app(CapabilityMapProvider::class)->mapFor($user);

        $cached = app(TenantCache::class)->get(CachedCapabilityMaps::suffix($user->identifier()));

        // The platform cache refuses to unserialize objects (gadget-chain
        // defense); the capability entry is a plain array by design, so a
        // poisoned entry can at worst be malformed — which decodes as a miss
        // and rebuilds server-side.
        expect($cached)->toBeArray()
            ->and(array_walk_recursive($cached, function (mixed $leaf): void {
                expect(is_object($leaf))->toBeFalse('cache entry contains an object');
            }))->toBeTrue();
        expect(serialize($cached))->not->toContain('password')
            ->not->toContain('two_factor_secret');

        // And it round-trips: the next read serves from cache, hydrated.
        expect(app(CapabilityMapProvider::class)->mapFor($user)->allows('customers.view'))->toBeTrue();
    });
});

it('degrades within the FS-01 latency budget shape: per-request DB resolution still answers', function () {
    [$tenant, $user] = cachedUserFixture();

    asTenant($tenant, function () use ($user): void {
        Cache::swap(new Repository(new class extends Illuminate\Cache\NullStore
        {
            public function get($key): mixed
            {
                throw new RuntimeException('cache down');
            }
        }));

        // Ten consecutive checks, all served from the database, none erroring.
        foreach (range(1, 10) as $i) {
            expect(app(CapabilityMapProvider::class)->verdictFor($user, 'customers.view')->allowed)->toBeTrue();
        }
    });
});
