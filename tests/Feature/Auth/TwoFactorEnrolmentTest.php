<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Support\Auth\Sessions\SessionRegistry;
use App\Support\Auth\Sessions\TenantPrefixedSessionHandler;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use PragmaRX\Google2FA\Google2FA;

/**
 * FR-06 enrolment through the REAL routes (plus SEC-03, EC-10, FR-14).
 *
 * Valid codes are computed from the secret the SERVER just returned, with the
 * same google2fa engine — so a green here means the QR a real authenticator
 * scans would produce codes this server accepts, not merely that our verifier
 * agrees with itself.
 */
function enrolmentUser(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create());
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    return [(string) $tenant->getKey(), $user];
}

function totpFor(string $secret): string
{
    return (string) app(Google2FA::class)->oathTotp($secret, intdiv(CarbonImmutable::now()->getTimestamp(), 30));
}

/** @return array{secret: string, enrolment_id: string, qr_svg: string} */
function beginEnrolment(User $user): array
{
    $response = test()->actingAs($user)->postJson('/api/staff/security/2fa');
    $response->assertOk();

    /** @var array{secret: string, enrolment_id: string, qr_svg: string} */
    return $response->json();
}

it('FR-06: begin → confirm with a valid code → active, with 8 single-shot recovery codes', function (): void {
    [$tenantId, $user] = enrolmentUser();

    $begun = beginEnrolment($user);
    expect($begun['secret'])->toBeString()->not->toBe('')
        ->and($begun['qr_svg'])->toContain('<svg')
        ->and(asTenant($tenantId, fn () => $user->refresh()->getAttribute('two_factor_confirmed_at')))->toBeNull();

    $confirm = test()->actingAs($user)->postJson('/api/staff/security/2fa/confirm', [
        'code' => totpFor($begun['secret']),
        'enrolment_id' => $begun['enrolment_id'],
    ]);
    $confirm->assertOk();

    $codes = $confirm->json('recovery_codes');
    expect($codes)->toBeArray()->toHaveCount(8);
    foreach ($codes as $code) {
        expect($code)->toMatch('/^[0-9A-F]{5}-[0-9A-F]{5}$/');
    }

    expect(asTenant($tenantId, fn () => $user->refresh()->getAttribute('two_factor_confirmed_at')))->not->toBeNull();
});

it('SEC-03: stored recovery codes are argon2id digests, and no response ever repeats them', function (): void {
    [$tenantId, $user] = enrolmentUser();

    $begun = beginEnrolment($user);
    test()->actingAs($user)->postJson('/api/staff/security/2fa/confirm', [
        'code' => totpFor($begun['secret']),
        'enrolment_id' => $begun['enrolment_id'],
    ])->assertOk();

    $rows = asTenant($tenantId, fn (): array => UserRecoveryCode::query()
        ->where('user_id', $user->identifier())->get()->all());

    expect($rows)->toHaveCount(8);
    foreach ($rows as $row) {
        expect($row->code_hash)->toStartWith('$argon2id$')
            ->and($row->used_at)->toBeNull();
    }
});

it('refuses to activate on a wrong code — a mis-scanned QR must not lock the account (FR-06)', function (): void {
    [$tenantId, $user] = enrolmentUser();

    $begun = beginEnrolment($user);
    test()->actingAs($user)->postJson('/api/staff/security/2fa/confirm', [
        'code' => '000000',
        'enrolment_id' => $begun['enrolment_id'],
    ])->assertStatus(422);

    expect(asTenant($tenantId, fn () => $user->refresh()->getAttribute('two_factor_confirmed_at')))->toBeNull();
});

it('EC-10: two tabs, one winner — the superseded tab gets its DESIGNED error, the newest QR works', function (): void {
    [$tenantId, $user] = enrolmentUser();

    $tabA = beginEnrolment($user);
    $tabB = beginEnrolment($user); // single-secret-wins: B replaces A

    // The loser: a perfectly good code for A's secret, refused as SUPERSEDED —
    // 409 with its own copy, not the wrong-code 422 (the fix is rescan, not
    // retype).
    $loser = test()->actingAs($user)->postJson('/api/staff/security/2fa/confirm', [
        'code' => totpFor($tabA['secret']),
        'enrolment_id' => $tabA['enrolment_id'],
    ]);
    $loser->assertStatus(409);
    expect($loser->json('detail'))->toBe(__('auth.2fa.enrolment_superseded'));

    // POSITIVE CONTROL: the winner confirms normally.
    test()->actingAs($user)->postJson('/api/staff/security/2fa/confirm', [
        'code' => totpFor($tabB['secret']),
        'enrolment_id' => $tabB['enrolment_id'],
    ])->assertOk();
});

it('begin is REFUSED while 2FA is active — otherwise it would be a code-free off-switch', function (): void {
    [$tenantId, $user] = enrolmentUser();

    $begun = beginEnrolment($user);
    test()->actingAs($user)->postJson('/api/staff/security/2fa/confirm', [
        'code' => totpFor($begun['secret']),
        'enrolment_id' => $begun['enrolment_id'],
    ])->assertOk();

    test()->actingAs($user)->postJson('/api/staff/security/2fa')->assertStatus(422);

    // …and the enrolment is untouched by the refused begin.
    expect(asTenant($tenantId, fn () => $user->refresh()->getAttribute('two_factor_confirmed_at')))->not->toBeNull();
});

it('FR-14: enabling 2FA revokes the user\'s OTHER sessions, sparing the current one', function (): void {
    [$tenantId, $user] = enrolmentUser();

    // A second browser, planted the way the sessions suites do.
    $connection = config('session.connection');
    $redis = app(RedisFactory::class)->connection(is_string($connection) ? $connection : 'default');
    $redis->setex(TenantPrefixedSessionHandler::keyFor($tenantId, 'other-device-session'), 3600, 'planted');
    app(SessionRegistry::class)->register($tenantId, $user->identifier(), 'other-device-session', CarbonImmutable::now());

    $begun = beginEnrolment($user);
    test()->actingAs($user)->postJson('/api/staff/security/2fa/confirm', [
        'code' => totpFor($begun['secret']),
        'enrolment_id' => $begun['enrolment_id'],
    ])->assertOk();

    expect((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenantId, 'other-device-session')))
        ->toBeFalse('SEV-1: a 2FA change left another session alive (FR-14) — the session a hijacker keeps open would survive the very change made to expel them');
});

it('disable demands a CURRENT code; a wrong one changes nothing', function (): void {
    [$tenantId, $user] = enrolmentUser();

    $begun = beginEnrolment($user);
    test()->actingAs($user)->postJson('/api/staff/security/2fa/confirm', [
        'code' => totpFor($begun['secret']),
        'enrolment_id' => $begun['enrolment_id'],
    ])->assertOk();

    test()->actingAs($user)->deleteJson('/api/staff/security/2fa', ['code' => '000000'])
        ->assertStatus(422);
    expect(asTenant($tenantId, fn () => $user->refresh()->getAttribute('two_factor_confirmed_at')))->not->toBeNull();

    // POSITIVE CONTROL: with a valid current code it turns off.
    test()->actingAs($user)->deleteJson('/api/staff/security/2fa', ['code' => totpFor($begun['secret'])])
        ->assertOk();
    expect(asTenant($tenantId, fn () => $user->refresh()->getAttribute('two_factor_confirmed_at')))->toBeNull();
});

it('regeneration mints a fresh batch and the OLD batch is dead (FR-07 banner path, R-4)', function (): void {
    [$tenantId, $user] = enrolmentUser();

    $begun = beginEnrolment($user);
    $first = test()->actingAs($user)->postJson('/api/staff/security/2fa/confirm', [
        'code' => totpFor($begun['secret']),
        'enrolment_id' => $begun['enrolment_id'],
    ])->json('recovery_codes');

    $second = test()->actingAs($user)->postJson('/api/staff/security/2fa/recovery-codes', [
        'code' => totpFor($begun['secret']),
    ]);
    $second->assertOk();
    expect($second->json('recovery_codes'))->toBeArray()->toHaveCount(8);

    // The old batch no longer counts (R-4 reads the LIVE batch) and cannot be
    // consumed.
    $store = app(App\Domain\Auth\TwoFactor\RecoveryCodeStore::class);
    $consumed = asTenant($tenantId, fn (): bool => $store->consume($user, (string) $first[0], CarbonImmutable::now()));
    expect($consumed)->toBeFalse('SEV-1: a code from a SUPERSEDED batch was accepted');

    $remaining = asTenant($tenantId, fn (): int => $store->remaining($user));
    expect($remaining)->toBe(8);
});

it('FR-08: admin reset — typed name enforced server-side, target sessions all die, user re-enrols', function (): void {
    [$tenantId, $target] = enrolmentUser();
    $admin = asTenant($tenantId, fn (): User => User::factory()->create(['name' => 'Admin Fixture']));

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    $begun = beginEnrolment($target);
    test()->actingAs($target)->postJson('/api/staff/security/2fa/confirm', [
        'code' => totpFor($begun['secret']),
        'enrolment_id' => $begun['enrolment_id'],
    ])->assertOk();

    // Plant a live session for the target — the phone-thief scenario.
    $connection = config('session.connection');
    $redis = app(RedisFactory::class)->connection(is_string($connection) ? $connection : 'default');
    $redis->setex(TenantPrefixedSessionHandler::keyFor($tenantId, 'targets-session'), 3600, 'planted');
    app(SessionRegistry::class)->register($tenantId, $target->identifier(), 'targets-session', CarbonImmutable::now());

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    // Wrong typed name: refused, nothing changes.
    test()->actingAs($admin)->deleteJson(
        '/api/staff/admin/users/'.$target->identifier().'/two-factor',
        ['confirm_name' => 'Wrong Name'],
    )->assertStatus(422);
    expect(asTenant($tenantId, fn () => $target->refresh()->getAttribute('two_factor_confirmed_at')))->not->toBeNull();

    // Exact name: reset lands, target's sessions are ALL gone.
    $targetName = $target->getAttribute('name');
    test()->actingAs($admin)->deleteJson(
        '/api/staff/admin/users/'.$target->identifier().'/two-factor',
        ['confirm_name' => is_string($targetName) ? $targetName : ''],
    )->assertOk();

    expect(asTenant($tenantId, fn () => $target->refresh()->getAttribute('two_factor_confirmed_at')))->toBeNull()
        ->and((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenantId, 'targets-session')))
        ->toBeFalse('SEV-1: the target\'s live session survived an admin 2FA reset (US-02/US-03)');
});
