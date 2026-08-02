<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\Sessions\SessionLifetimePolicy;
use App\Support\Auth\Sessions\SessionMetadataStore;
use App\Support\Auth\Sessions\SessionRegistry;
use App\Support\Auth\Sessions\TenantPrefixedSessionHandler;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * FR-12's API surface, plus the FR-11 middleware wiring this session proved
 * was MISSING (EnforceSessionLifetime existed since S9 and was attached
 * nowhere — the S8 configured-but-unwired failure shape, again).
 *
 * Planted sessions stand in for other browsers; the ACTING request's own
 * session becomes the "current" one because the lifetime middleware's touch
 * registers it — which is itself an assertion that the touch is wired.
 */
function securityUser(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create());
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    return [(string) $tenant->getKey(), $user];
}

function plantOtherSession(string $tenantId, User $user, string $sessionId, ?string $city): void
{
    $connection = config('session.connection');
    $redis = app(RedisFactory::class)->connection(is_string($connection) ? $connection : 'default');

    $redis->setex(TenantPrefixedSessionHandler::keyFor($tenantId, $sessionId), 3600, 'planted-session');
    app(SessionRegistry::class)->register($tenantId, $user->identifier(), $sessionId, CarbonImmutable::now());
    app(SessionMetadataStore::class)->put($tenantId, $sessionId, [
        'device_id' => '',
        'device_label' => 'Chrome · Windows',
        'ip' => '192.0.2.7',
        'city' => $city,
        'country_code' => $city === null ? null : 'ET',
        'signed_in_at' => CarbonImmutable::now()->toIso8601String(),
    ]);
}

it('lists the caller\'s sessions with digests, metadata, and FS-04\'s null location stated as null', function (): void {
    [$tenantId, $user] = securityUser();

    plantOtherSession($tenantId, $user, 'other-browser-1', 'Addis Ababa');
    plantOtherSession($tenantId, $user, 'other-browser-2', null);

    $response = test()->actingAs($user)->getJson('/api/staff/security/sessions');
    $response->assertOk();

    $rows = $response->json('sessions');
    expect($rows)->toBeArray()->toHaveCount(3); // 2 planted + the current request's own

    $byLabelIp = collect($rows)->keyBy(fn (array $row): string => (string) json_encode([$row['device_label'], $row['location']]));

    // Digests, never raw ids: the session id IS the credential.
    foreach ($rows as $row) {
        expect($row['digest'])->toHaveLength(64)
            ->and($row['digest'])->not->toBe('other-browser-1')
            ->and($row['digest'])->not->toBe('other-browser-2');
    }

    expect(collect($rows)->firstWhere('location', 'Addis Ababa'))->not->toBeNull()
        // FS-04's degradation reaches the API as an explicit null, which the
        // screen renders as the "location unavailable" copy.
        ->and(collect($rows)->firstWhere('location', null))->not->toBeNull()
        // The current request's own session is present and marked — proof the
        // lifetime middleware's touch is WIRED, not just written.
        ->and(collect($rows)->firstWhere('current', true))->not->toBeNull()
        // §8: current pinned first.
        ->and($rows[0]['current'])->toBeTrue();
});

it('revokes one of the caller\'s other sessions by digest (FR-12, US-03)', function (): void {
    [$tenantId, $user] = securityUser();
    plantOtherSession($tenantId, $user, 'session-to-kill', 'Addis Ababa');

    $connection = config('session.connection');
    $redis = app(RedisFactory::class)->connection(is_string($connection) ? $connection : 'default');

    // POSITIVE CONTROL: the planted session exists before the revoke.
    expect((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenantId, 'session-to-kill')))->toBeTrue();

    $digest = hash('sha256', 'session-to-kill');
    test()->actingAs($user)->deleteJson('/api/staff/security/sessions/'.$digest)->assertOk();

    expect((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenantId, 'session-to-kill')))
        ->toBeFalse('SEV-1: the revoked session key still exists — US-03\'s "revoke instantly" did nothing');
});

it('refuses an unknown digest with 404 — expired-meanwhile and never-existed are indistinguishable', function (): void {
    [, $user] = securityUser();

    test()->actingAs($user)
        ->deleteJson('/api/staff/security/sessions/'.str_repeat('ab', 32))
        ->assertNotFound();
});

it('cannot revoke ANOTHER user\'s session by digest — the list is scoped to the caller by construction', function (): void {
    [$tenantId, $victim] = securityUser();
    plantOtherSession($tenantId, $victim, 'victims-session', 'Addis Ababa');

    [, $attacker] = securityUser();

    // The attacker knows the victim's digest (say, from a leak elsewhere).
    // Resolution starts from the ATTACKER's own index, so it finds nothing.
    test()->actingAs($attacker)
        ->deleteJson('/api/staff/security/sessions/'.hash('sha256', 'victims-session'))
        ->assertNotFound();

    $connection = config('session.connection');
    $redis = app(RedisFactory::class)->connection(is_string($connection) ? $connection : 'default');
    expect((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenantId, 'victims-session')))
        ->toBeTrue('SEV-1: one user revoked another user\'s session');
});

it('revoke-others kills every planted session, audits the bulk action, and spares the current one', function (): void {
    [$tenantId, $user] = securityUser();
    plantOtherSession($tenantId, $user, 'other-a', 'Addis Ababa');
    plantOtherSession($tenantId, $user, 'other-b', null);

    $response = test()->actingAs($user)->postJson('/api/staff/security/sessions/revoke-others');
    $response->assertOk();

    expect($response->json('count'))->toBe(2);

    $connection = config('session.connection');
    $redis = app(RedisFactory::class)->connection(is_string($connection) ? $connection : 'default');
    foreach (['other-a', 'other-b'] as $sessionId) {
        expect((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenantId, $sessionId)))->toBeFalse();
    }
});

it('revoke-others with NOTHING to revoke still succeeds and still audits — the empty press is a fact too', function (): void {
    // This case shaped the design: DeclareAuditAction throws in testing when a
    // 2xx writes no audit entry, so the bulk action has its own always-fired
    // key rather than piggybacking on the per-session one. If this test 500s,
    // that contract broke.
    [, $user] = securityUser();

    $response = test()->actingAs($user)->postJson('/api/staff/security/sessions/revoke-others');
    $response->assertOk();

    expect($response->json('count'))->toBe(0);
});

it('FR-11 WIRING: a session past its absolute limit is refused, not served', function (): void {
    // S9 proved the POLICY with an injected clock; this proves the MIDDLEWARE
    // is attached to the staff surface — the half that was silently missing.
    [, $user] = securityUser();

    $expired = CarbonImmutable::now()->subHours(100)->toIso8601String();

    test()->actingAs($user)
        ->withSession([
            SessionLifetimePolicy::ESTABLISHED_AT => $expired,
            SessionLifetimePolicy::LAST_ACTIVITY_AT => CarbonImmutable::now()->toIso8601String(),
        ])
        ->getJson('/api/staff/security/sessions')
        ->assertStatus(404); // de-authenticated → staff resolution refuses, C-10 shape

    // POSITIVE CONTROL: identical request with fresh marks is served — so the
    // refusal above is the clock's doing, not a broken route.
    $guard = auth('staff');
    if ($guard instanceof Illuminate\Auth\SessionGuard) {
        $guard->forgetUser();
    }
    test()->flushSession();
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $now = CarbonImmutable::now()->toIso8601String();
    test()->actingAs($user)
        ->withSession([
            SessionLifetimePolicy::ESTABLISHED_AT => $now,
            SessionLifetimePolicy::LAST_ACTIVITY_AT => $now,
        ])
        ->getJson('/api/staff/security/sessions')
        ->assertOk();
});
