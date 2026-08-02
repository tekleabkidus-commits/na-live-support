<?php

declare(strict_types=1);

use App\Domain\Auth\Tokens\MintedToken;
use App\Domain\Auth\Tokens\TokenMinter;
use App\Models\User;
use App\Support\Auth\Tokens\TokenScopeGate;
use App\Support\Rbac\EffectivePermissions;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/**
 * AC-05 / T-05 / EC-06 / FR-20's 403: the token-scope intersection
 * (Doc 02 §4.5; step-9 ruling; traceability ruling Q5).
 *
 * PARTIAL BY OWNER RULING Q5, stated here as loudly as in the traceability
 * registry: AC-05's literal wording — "tickets.read token gets 403 naming
 * tickets.reply on reply" — needs Doc 03's real resolver and Doc 06's ticket
 * routes. What THIS suite proves, against the permission keys this document
 * registered and the EffectivePermissions seam, is every mechanism that
 * sentence exercises: the intersection arithmetic (both directions — ability
 * ceiling AND live owner permissions), the 403 that NAMES the missing key,
 * the 10-second cache bound of EC-06, and the audit/event trail of a denial.
 * When Doc 03 and Doc 06 land, the literal fixture is a re-run of this shape
 * with their artifacts.
 *
 * THE TEST ROUTES ARE TEST-MOUNTED ON THE REAL BEARER STACK (ruling Q-A): the
 * production surface is probe-only until Docs 04/06 mount real endpoints, so
 * the permission-gated routes here are registered by the suite — with exactly
 * the middleware chain production routes will declare. The stack is real; only
 * the handler is a stub.
 */
function scopeFixture(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'scope.owner@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $user];
}

function mintScoped(mixed $tenant, User $user, array $abilities): MintedToken
{
    $minted = asTenant($tenant, fn (): MintedToken => app(TokenMinter::class)->mint(
        owner: $user,
        name: 'scope suite',
        abilities: $abilities,
        expiryDays: 90,
        now: CarbonImmutable::now(),
    ));

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return $minted;
}

function scopeRequest(string $method, string $path, string $plaintext): Illuminate\Testing\TestResponse
{
    // Same reason as bearerGet(): production guards are per-request (FPM, and
    // Octane's FlushAuthenticationState); the harness's are not, and a cached
    // api-guard principal would pin every later request to the FIRST token.
    app('auth')->forgetGuards();

    return test()->json($method, $path, [], ['Authorization' => 'Bearer '.$plaintext]);
}

beforeEach(function (): void {
    // The permission-gated routes AC-05 needs, on the REAL bearer middleware
    // chain (see the file docblock). `api/` prefix so refusals render as
    // problem-details JSON, like every real API route.
    Route::middleware(['tenant.bearer', 'auth:api', 'tenant.state:outbound', 'permission:settings.view'])
        ->get('api/v1/scope-suite/read', fn () => response()->json(['ok' => 'read']));

    Route::middleware(['tenant.bearer', 'auth:api', 'tenant.state:outbound', 'permission:settings.edit'])
        ->put('api/v1/scope-suite/write', fn () => response()->json(['ok' => 'write']));
});

it('refuses the write with 403 NAMING settings.edit while the SAME token reads fine (AC-05\'s shape)', function (): void {
    [$tenant, $user] = scopeFixture();

    // The AC-05 fixture translated to this document's registered keys: a
    // read-scoped token (settings.view standing in for tickets.read) against
    // a write route (settings.edit standing in for tickets.reply).
    $minted = mintScoped($tenant, $user, ['settings.view']);

    // POSITIVE CONTROL FIRST: the token authenticates and its granted scope
    // works — the 403 below is about SCOPE, not a broken surface.
    scopeRequest('GET', 'https://localhost/api/v1/scope-suite/read', $minted->plaintext)
        ->assertOk()
        ->assertJsonPath('ok', 'read');

    $denied = scopeRequest('PUT', 'https://localhost/api/v1/scope-suite/write', $minted->plaintext);

    $denied->assertStatus(403)
        ->assertJsonPath('code', 'insufficient_scope')
        // FR-20/AC-05: the refusal NAMES the missing permission, in both the
        // machine-readable field and the human detail.
        ->assertJsonPath('missing_permission', 'settings.edit');

    expect($denied->json('detail'))->toContain('settings.edit');
});

it('audits and publishes the denial (auth.token.scope_denied, BR-03 attribution)', function (): void {
    [$tenant, $user] = scopeFixture();
    $minted = mintScoped($tenant, $user, ['settings.view']);

    scopeRequest('PUT', 'https://localhost/api/v1/scope-suite/write', $minted->plaintext)
        ->assertStatus(403);

    $audit = asTenant($tenant, fn () => DB::table('audit_log')
        ->where('action_key', 'auth.token.scope_denied')
        ->where('entity_id', $minted->token->identifier())
        ->first());

    expect($audit)->not->toBeNull('the denial must land in the audit trail')
        ->and($audit->actor_type)->toBe('staff')
        ->and($audit->actor_id)->toBe($user->identifier());

    expect(json_decode((string) $audit->payload, true)['permission'])->toBe('settings.edit');

    $event = asTenant($tenant, fn () => DB::table('outbox')->where('name', 'auth.token.scope_denied')->first());
    $payload = json_decode((string) $event->payload, true);

    expect(array_keys($payload))->toEqualCanonicalizing(['tenant_id', 'user_id', 'token_id', 'permission'])
        ->and($payload['permission'])->toBe('settings.edit')
        ->and($payload['token_id'])->toBe($minted->token->identifier());
});

it('never lets a token EXCEED its owner — an ability the owner lacks is dead scope (T-05, FR-18)', function (): void {
    [$tenant, $user] = scopeFixture();

    // The token's stored abilities include settings.edit; the OWNER's live
    // permissions (faked at the seam Doc 03 will fill) do not. The stored
    // ability is a ceiling, never a grant.
    $minted = mintScoped($tenant, $user, ['settings.view', 'settings.edit']);

    $fake = new class implements EffectivePermissions
    {
        /** @var list<string> */
        public array $grant = ['settings.view'];

        public function for(User $user): array
        {
            return $this->grant;
        }
    };
    app()->instance(EffectivePermissions::class, $fake);

    scopeRequest('PUT', 'https://localhost/api/v1/scope-suite/write', $minted->plaintext)
        ->assertStatus(403)
        ->assertJsonPath('missing_permission', 'settings.edit');

    // POSITIVE CONTROL, second, through a FRESH cache window (distinct token
    // id per mint means no cache carryover): grant the permission and the same
    // ability comes alive — proving the 403 above was the intersection, not a
    // broken route.
    $fake->grant = ['settings.view', 'settings.edit'];
    $minted2 = mintScoped($tenant, $user, ['settings.view', 'settings.edit']);

    scopeRequest('PUT', 'https://localhost/api/v1/scope-suite/write', $minted2->plaintext)
        ->assertOk();
});

it('propagates permission loss within T-05\'s 10-second cache bound — and not before (EC-06, AC-05)', function (): void {
    [$tenant, $user] = scopeFixture();
    $minted = mintScoped($tenant, $user, ['settings.edit']);

    $fake = new class implements EffectivePermissions
    {
        /** @var list<string> */
        public array $grant = ['settings.edit'];

        public function for(User $user): array
        {
            return $this->grant;
        }
    };
    app()->instance(EffectivePermissions::class, $fake);

    // POSITIVE CONTROL: allowed while the owner holds the permission. This
    // also warms the per-token cache.
    scopeRequest('PUT', 'https://localhost/api/v1/scope-suite/write', $minted->plaintext)->assertOk();

    // The owner loses the permission…
    $fake->grant = [];

    // …and the VERY NEXT request still passes: T-05's cache serves the stale
    // verdict inside the window. This assertion is what makes the 10-second
    // bound a DOCUMENTED behaviour rather than an accident — deleting the
    // cache would flip this line red, not just slow things down.
    scopeRequest('PUT', 'https://localhost/api/v1/scope-suite/write', $minted->plaintext)->assertOk();

    // The window itself is Redis physics: the cache row exists and its TTL is
    // positive and ≤ 10 s, so the stale allow CANNOT outlive EC-06's bound.
    $store = Cache::getStore();
    assert($store instanceof Illuminate\Cache\RedisStore);
    $rawKey = $store->getPrefix().sprintf('t:%s:tokscope:%s', $tenant->id, $minted->token->identifier());
    $ttl = Redis::connection((string) config('cache.stores.redis.connection'))->client()->ttl($rawKey);

    // LITERAL 10, not TokenScopeGate::CACHE_TTL_SECONDS: the bound is T-05's
    // specification. Asserting against the code's own constant went green when
    // the constant was sabotaged to 3600 — the assertion moved with the fault
    // it existed to catch (found by exactly that sabotage run).
    expect($ttl)->toBeGreaterThan(0, 'the scope cache entry must exist with a TTL')
        ->toBeLessThanOrEqual(10, 'EC-06/T-05: the stale window may never exceed the specified 10 s');

    // Cross the window (the TTL above proves Redis will do exactly this on
    // its own within 10 s) and the loss lands: 403 naming the key. The
    // cache key is tenant-prefixed (C-07), so the forget binds the tenant.
    asTenant($tenant, fn () => app(TokenScopeGate::class)->forget($minted->token));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    scopeRequest('PUT', 'https://localhost/api/v1/scope-suite/write', $minted->plaintext)
        ->assertStatus(403)
        ->assertJsonPath('missing_permission', 'settings.edit');
});

it('drops the cached verdict on revocation rather than serving it out to the TTL', function (): void {
    [$tenant, $user] = scopeFixture();
    $minted = mintScoped($tenant, $user, ['settings.edit']);

    // Warm the cache with an allow (positive control in passing).
    scopeRequest('PUT', 'https://localhost/api/v1/scope-suite/write', $minted->plaintext)->assertOk();

    asTenant($tenant, fn () => app(App\Domain\Auth\Tokens\TokenRevoker::class)
        ->revoke($minted->token, 'self_revoked', CarbonImmutable::now(), $user->identifier()));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // Dead at the door — and the cache entry is gone too, so a
    // revoked-then-reissued token id could never inherit a stale allow.
    scopeRequest('PUT', 'https://localhost/api/v1/scope-suite/write', $minted->plaintext)
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_token');

    $store = Cache::getStore();
    assert($store instanceof Illuminate\Cache\RedisStore);
    $rawKey = $store->getPrefix().sprintf('t:%s:tokscope:%s', $tenant->id, $minted->token->identifier());
    $exists = Redis::connection((string) config('cache.stores.redis.connection'))->client()->exists($rawKey);

    expect((int) $exists)->toBe(0, 'revocation must forget the scope cache, not wait out the TTL');
});
