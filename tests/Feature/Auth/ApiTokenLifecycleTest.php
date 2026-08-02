<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * FR-18 personal tokens end to end, FR-20's closed code set, T-06's stateless
 * bearer surface, and the guard partition (Doc 02 §4.5; step-9 rulings).
 *
 * THE POSITIVE CONTROL COMES FIRST, by protocol: every refusal assertion in
 * this file is only meaningful because the first test proves a freshly minted
 * token genuinely authenticates the bearer surface — two refusals compared to
 * each other are byte-identical whether the surface works or is entirely
 * broken (the AC-01 lesson, recorded in EnumerationTest's docblock).
 */
function tokenLifecycleFixture(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'token.owner@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    return [$tenant, $user];
}

function mintTokenViaApi(User $user, array $overrides = []): TestResponse
{
    // actingAs NAMES the guard: a successful auth:api request calls
    // shouldUse('api'), flipping the harness's default guard for the rest of
    // the test (production config is per-request; the harness's is not) — an
    // unnamed actingAs would then plant the user on the WRONG guard and every
    // staff request after a bearer request would 401.
    return test()->actingAs($user, 'staff')->postJson('https://localhost/api/staff/security/tokens', array_merge([
        'name' => 'CI integration',
        'abilities' => ['settings.view'],
        'expiry' => '90',
    ], $overrides));
}

function bearerGet(string $plaintext, string $path = 'https://localhost/api/v1/token/context'): TestResponse
{
    // Production never reuses guard state across requests — FPM builds a fresh
    // app, Octane's FlushAuthenticationState forgets guards between requests.
    // The test harness keeps one app across in-test requests, so without this
    // the api RequestGuard would serve request 1's principal to request 2 and
    // every multi-token assertion would silently test the wrong token.
    app('auth')->forgetGuards();

    return test()->getJson($path, ['Authorization' => 'Bearer '.$plaintext]);
}

it('mints a personal token that AUTHENTICATES the bearer surface (FR-18 — the positive control)', function (): void {
    [$tenant, $user] = tokenLifecycleFixture();

    $create = mintTokenViaApi($user)->assertCreated();
    $plaintext = $create->json('plaintext');

    expect($plaintext)->toMatch('/^nlt_[0-9a-f]{64}$/');

    bearerGet($plaintext)
        ->assertOk()
        ->assertJsonPath('resolved_tenant_id', $tenant->id)
        // FR-03a: the bearer path resolves through the staff_session source —
        // token → user → tenant, exactly as the enum has promised since Doc 01.
        ->assertJsonPath('resolution_source', 'staff_session');
});

it('stores only the SHA-256 — the plaintext exists in exactly one response (FR-18 "shown once")', function (): void {
    [$tenant, $user] = tokenLifecycleFixture();

    $create = mintTokenViaApi($user)->assertCreated();
    $plaintext = $create->json('plaintext');
    $tokenId = $create->json('token.id');

    $row = asTenant($tenant, fn (): ?PersonalAccessToken => PersonalAccessToken::query()->find($tokenId));

    expect($row)->not->toBeNull()
        ->and($row->getRawOriginal('token'))->toBe(hash('sha256', $plaintext))
        ->and($row->getRawOriginal('token'))->not->toBe($plaintext);

    // The list surface re-shows metadata, never the credential — neither the
    // plaintext nor the hash appears anywhere in it.
    $list = test()->actingAs($user, 'staff')->getJson('https://localhost/api/staff/security/tokens')->assertOk();

    expect($list->getContent())
        ->not->toContain($plaintext)
        ->and($list->getContent())->not->toContain(hash('sha256', $plaintext));
});

it('surfaces last_used_at after a bearer request (FR-18 "last-used displayed")', function (): void {
    [, $user] = tokenLifecycleFixture();

    $create = mintTokenViaApi($user)->assertCreated();
    $tokenId = $create->json('token.id');

    expect($create->json('token.last_used_at'))->toBeNull();

    bearerGet($create->json('plaintext'))->assertOk();

    $list = test()->actingAs($user, 'staff')->getJson('https://localhost/api/staff/security/tokens')->assertOk();
    $row = collect($list->json('tokens'))->firstWhere('id', $tokenId);

    expect($row['last_used_at'])->not->toBeNull();
});

it('revokes a token individually, after proving it worked (FR-18 "individually revocable")', function (): void {
    [, $user] = tokenLifecycleFixture();

    $create = mintTokenViaApi($user)->assertCreated();
    $plaintext = $create->json('plaintext');
    $tokenId = $create->json('token.id');

    // POSITIVE CONTROL: alive before the revoke.
    bearerGet($plaintext)->assertOk();

    test()->actingAs($user, 'staff')
        ->deleteJson('https://localhost/api/staff/security/tokens/'.$tokenId)
        ->assertOk();

    bearerGet($plaintext)
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_token');
});

it('refuses expiry choices outside FR-18\'s fixed set, and "none" once the policy forbids it', function (): void {
    [$tenant, $user] = tokenLifecycleFixture();

    // The fixed set is specification: 45 is not a choice.
    mintTokenViaApi($user, ['expiry' => '45'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    // Default policy allows "none" (auth.token.allow_no_expiry default true)…
    $noExpiry = mintTokenViaApi($user, ['expiry' => 'none'])->assertCreated();
    expect($noExpiry->json('token.expires_at'))->toBeNull();

    // …and the tenant can turn it off (FR-18 "per policy setting").
    asTenant($tenant, fn () => app(App\Support\Settings\SettingsRepository::class)->set(
        'auth.token.allow_no_expiry',
        false,
        App\Support\Audit\AuditActor::System,
    ));

    mintTokenViaApi($user, ['expiry' => 'none'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('refuses abilities that are not registered permission keys (FR-18 "selected from the catalog")', function (): void {
    [, $user] = tokenLifecycleFixture();

    mintTokenViaApi($user, ['abilities' => ['tickets.made_up_key']])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

/*
|------------------------------------------------------------------------------
| FR-20 — the closed code set (step-9 ruling)
|------------------------------------------------------------------------------
*/

it('answers a never-issued token with 401 invalid_token (FR-20)', function (): void {
    tokenLifecycleFixture();

    bearerGet('nlt_'.str_repeat('ab', 32))
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_token');
});

it('answers a missing Authorization header with the same 401 invalid_token', function (): void {
    tokenLifecycleFixture();

    test()->getJson('https://localhost/api/v1/token/context')
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_token');
});

it('answers an EXPIRED token with 401 token_expired — the one distinction FR-20 grants', function (): void {
    [$tenant, $user] = tokenLifecycleFixture();

    $create = mintTokenViaApi($user, ['expiry' => '30'])->assertCreated();
    $plaintext = $create->json('plaintext');
    $tokenId = $create->json('token.id');

    // POSITIVE CONTROL first: alive while unexpired.
    bearerGet($plaintext)->assertOk();

    // Age the ROW, not the clock: the door computes expiry in the database
    // (`expires_at <= now()`, C-11), which Carbon::setTestNow cannot move.
    asTenant($tenant, function () use ($tokenId): void {
        PersonalAccessToken::query()->whereKey($tokenId)->first()
            ?->forceFill(['expires_at' => CarbonImmutable::now()->subMinute()])->save();
    });

    bearerGet($plaintext)
        ->assertStatus(401)
        ->assertJsonPath('code', 'token_expired');
});

it('renders revoked-token and disabled-owner refusals BYTE-IDENTICAL to a never-issued token (step-9 ruling)', function (): void {
    [$tenant, $user] = tokenLifecycleFixture();

    // Revoked token.
    $revoked = mintTokenViaApi($user)->assertCreated();
    test()->actingAs($user, 'staff')->deleteJson('https://localhost/api/staff/security/tokens/'.$revoked->json('token.id'))->assertOk();

    // Disabled owner, token NOT revoked — the door's user_disabled flag, alone.
    $owner2 = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'second.owner@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));
    test()->flushSession();
    $disabledOwners = mintTokenViaApi($owner2)->assertCreated();
    asTenant($tenant, fn () => $owner2->forceFill(['disabled_at' => CarbonImmutable::now()])->save());

    $unknown = bearerGet('nlt_'.str_repeat('cd', 32));
    $revokedResponse = bearerGet($revoked->json('plaintext'));
    $disabledResponse = bearerGet($disabledOwners->json('plaintext'));

    expect($revokedResponse->getStatusCode())->toBe(401)
        ->and($revokedResponse->getContent())->toBe($unknown->getContent())
        ->and($disabledResponse->getContent())->toBe($unknown->getContent());
});

/*
|------------------------------------------------------------------------------
| T-02 partition + T-06 statelessness
|------------------------------------------------------------------------------
*/

it('never lets a session cookie satisfy the bearer surface (T-02; sanctum.guard = [])', function (): void {
    [, $user] = tokenLifecycleFixture();

    // A fully authenticated staff session…
    test()->actingAs($user, 'staff')->getJson('https://localhost/api/staff/context')->assertOk();

    // …is nothing on the bearer surface: no token, no service.
    test()->getJson('https://localhost/api/v1/token/context')
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_token');
});

it('never lets a bearer token satisfy the staff session surface (T-02, the other direction)', function (): void {
    [$tenant, $user] = tokenLifecycleFixture();

    // Minted DIRECTLY, not over the staff API: actingAs() would leave the
    // staff guard authenticated for the rest of the test, and the staff
    // surface would then 200 for a reason that has nothing to do with the
    // bearer header — the partition assertion would be vacuous.
    $minted = asTenant($tenant, fn () => app(App\Domain\Auth\Tokens\TokenMinter::class)->mint(
        owner: $user,
        name: 'partition probe',
        abilities: ['settings.view'],
        expiryDays: 90,
        now: CarbonImmutable::now(),
    ));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    $plaintext = $minted->plaintext;

    // POSITIVE CONTROL: the same token works where it belongs.
    bearerGet($plaintext)->assertOk();

    // The staff surface resolves sessions, not tokens: a request with a
    // perfectly valid bearer header and no session is simply UNAUTHENTICATED
    // there — the header is never consulted, and the refusal is the same 401
    // an expired session gets (EC-02's re-login posture on the staff API).
    // The load-bearing assertion is that it is not a 200.
    test()->getJson('https://localhost/api/staff/security/tokens', ['Authorization' => 'Bearer '.$plaintext])
        ->assertStatus(401);
});

it('audits and publishes creation and revocation (§4.8: auth.token.created|revoked)', function (): void {
    [$tenant, $user] = tokenLifecycleFixture();

    $create = mintTokenViaApi($user)->assertCreated();
    $tokenId = $create->json('token.id');

    test()->actingAs($user, 'staff')->deleteJson('https://localhost/api/staff/security/tokens/'.$tokenId)->assertOk();

    $audits = asTenant($tenant, fn () => DB::table('audit_log')
        ->where('entity', 'personal_access_token')
        ->where('entity_id', $tokenId)
        // By id, not created_at: both rows can land in the same DB clock tick,
        // and UUIDv7 ids are the platform's tie-breaker (C-11).
        ->orderBy('id')
        ->get(['action_key', 'actor_type', 'actor_id', 'payload']));

    expect($audits->pluck('action_key')->all())->toBe(['auth.token.created', 'auth.token.revoked'])
        ->and($audits->every(fn (object $row): bool => $row->actor_type === 'staff' && $row->actor_id === $user->identifier()))->toBeTrue();

    $created = json_decode((string) $audits[0]->payload, true);
    expect($created['kind'])->toBe('personal')
        ->and($created['abilities'])->toBe(['settings.view']);

    // The bus half — identifier-only payloads (step-9 ruling).
    $events = asTenant($tenant, fn () => DB::table('outbox')
        ->whereIn('name', ['auth.token.created', 'auth.token.revoked'])
        ->get(['name', 'payload']));

    expect($events)->toHaveCount(2);

    foreach ($events as $event) {
        $payload = json_decode((string) $event->payload, true);
        expect(array_keys($payload))->toEqualCanonicalizing(['tenant_id', 'user_id', 'token_id'])
            ->and($payload['token_id'])->toBe($tokenId);
    }
});

it('keeps two tenants\' tokens fully apart — each resolves only its own tenant', function (): void {
    [$tenantA, $userA] = tokenLifecycleFixture();
    $plaintextA = mintTokenViaApi($userA)->assertCreated()->json('plaintext');

    // Second tenant, second owner, second token.
    $tenantB = createTenant();
    $userB = asTenant($tenantB, fn (): User => User::factory()->create([
        'email' => 'token.owner@fixture.example', // same address, different tenant — deliberately
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenantB);
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    $plaintextB = mintTokenViaApi($userB)->assertCreated()->json('plaintext');

    bearerGet($plaintextA)->assertOk()->assertJsonPath('resolved_tenant_id', $tenantA->id);
    bearerGet($plaintextB)->assertOk()->assertJsonPath('resolved_tenant_id', $tenantB->id);
});
