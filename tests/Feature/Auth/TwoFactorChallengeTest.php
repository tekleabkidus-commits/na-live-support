<?php

declare(strict_types=1);

use App\Domain\Auth\TwoFactor\RecoveryCodeStore;
use App\Domain\Auth\TwoFactor\TotpVerifier;
use App\Domain\Auth\TwoFactor\TwoFactorEnrolment;
use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * FR-07's challenge and AC-03's clauses (±1/±2, 5-failure void, single-use).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HARNESS SHAPE. The PHP client does not replay cookies (the S6 lesson), so a
 * real login's pending state cannot survive into a second request here. The
 * two halves are proven separately — the login half (password → 401
 * two_factor_required with NO session) and the challenge half (pending state
 * planted via withSession, exactly the keys PendingChallenge writes) — and the
 * browser suite stitches them end-to-end over a real cookie jar.
 *
 * AC-03's boundary clauses run against TotpVerifier with an INJECTED clock at
 * exact 30-second steps (CLAUDE.md §8) — no sleeping across step boundaries,
 * no flakes at second 29.
 * ─────────────────────────────────────────────────────────────────────────────
 */
function challengeUser(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'challenge@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenant);

    $tenantId = (string) $tenant->getKey();

    // Enable 2FA through the real service (not a hand-rolled fixture), with a
    // code computed from the same engine an authenticator uses.
    [$secret, $codes] = asTenant($tenantId, function () use ($user): array {
        $enrolment = app(TwoFactorEnrolment::class);
        $begun = $enrolment->begin($user, 'Fixture Operator');
        $code = (string) app(Google2FA::class)->oathTotp($begun['secret'], intdiv(CarbonImmutable::now()->getTimestamp(), 30));
        $codes = $enrolment->confirm($user, $code, $begun['enrolment_id'], CarbonImmutable::now());

        return [$begun['secret'], $codes];
    });

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    $guard = auth('staff');
    if ($guard instanceof Illuminate\Auth\SessionGuard) {
        $guard->forgetUser();
    }

    return [$tenantId, $user, $secret, $codes];
}

function totpNow(string $secret): string
{
    return (string) app(Google2FA::class)->oathTotp($secret, intdiv(CarbonImmutable::now()->getTimestamp(), 30));
}

/** @return array<string, mixed> the session state PendingChallenge would have written */
function pendingStateFor(string $tenantId, User $user, int $attempts = 0): array
{
    return [
        '2fa.pending_user_id' => $user->identifier(),
        '2fa.pending_tenant_id' => $tenantId,
        '2fa.pending_remember' => false,
        '2fa.pending_attempts' => $attempts,
    ];
}

it('the login half: a correct password on a 2FA account yields 401 two_factor_required and NO session', function (): void {
    challengeUser();

    $response = test()->postJson('/login', [
        'email' => 'challenge@fixture.example',
        'password' => 'the-correct-password',
    ]);

    $response->assertStatus(401);
    expect($response->json('status'))->toBe('two_factor_required');

    // SS-02: zero session state for a valid-password-no-TOTP caller. The guard
    // holds no user and the session carries no auth key.
    expect(auth('staff')->user())->toBeNull(
        'SEV-1 (SS-02): the password alone authenticated a 2FA account — the challenge is decorative'
    );
});

it('positive control: the challenge half establishes a session for a valid TOTP', function (): void {
    [$tenantId, $user, $secret] = challengeUser();

    $response = test()->withSession(pendingStateFor($tenantId, $user))
        ->postJson('/login/two-factor', ['code' => totpNow($secret)]);

    $response->assertOk();
    expect($response->json('status'))->toBe('signed_in');
});

it('AC-03: ±1 step accepted, ±2 rejected — exact boundaries, injected clock', function (): void {
    $google2fa = app(Google2FA::class);
    $verifier = app(TotpVerifier::class);
    $secret = $google2fa->generateSecretKey();

    $now = CarbonImmutable::parse('2026-08-02T12:00:15Z'); // mid-step, no edge ambiguity
    $counter = intdiv($now->getTimestamp(), 30);

    foreach ([-1, 0, 1] as $offset) {
        $code = (string) $google2fa->oathTotp($secret, $counter + $offset);
        expect($verifier->verify($secret, $code, $now))
            ->toBeTrue(sprintf('a code %+d step(s) away must be ACCEPTED (FR-07/EC-01: ≤30 s of phone skew)', $offset));
    }

    foreach ([-2, 2] as $offset) {
        $code = (string) $google2fa->oathTotp($secret, $counter + $offset);
        expect($verifier->verify($secret, $code, $now))
            ->toBeFalse(sprintf('SEV-1: a code %+d steps away was accepted — the guessing window is wider than FR-07 specifies', $offset));
    }

    // FR-07: 6 digits only; malformed input never reaches the crypto.
    expect($verifier->verify($secret, '12345', $now))->toBeFalse()
        ->and($verifier->verify($secret, 'abcdef', $now))->toBeFalse();
});

it('a wrong code is refused and the account is NOT signed in', function (): void {
    [$tenantId, $user] = challengeUser();

    $response = test()->withSession(pendingStateFor($tenantId, $user))
        ->postJson('/login/two-factor', ['code' => '000000']);

    $response->assertStatus(422);
    expect(auth('staff')->user())->toBeNull();
});

it('FR-07: the FIFTH failure voids the attempt, counts ONE failure toward lockout, and clears the pending state', function (): void {
    [$tenantId, $user] = challengeUser();

    $before = (int) asTenant($tenantId, fn () => $user->refresh()->getAttribute('failed_login_attempts'));

    // Attempts 1–4 have already burned (planted); this is the fifth.
    $fifth = test()->withSession(pendingStateFor($tenantId, $user, attempts: 4))
        ->postJson('/login/two-factor', ['code' => '000000']);

    $fifth->assertStatus(422);
    expect($fifth->json('detail'))->toBe(__('auth.2fa.voided'));

    $after = (int) asTenant($tenantId, fn () => $user->refresh()->getAttribute('failed_login_attempts'));
    expect($after)->toBe($before + 1, 'the void must count exactly ONE failed login attempt (FR-07)');

    // The pending state is gone: the next code, however correct, has nothing
    // to verify against — back to the password.
    expect(test()->postJson('/login/two-factor', ['code' => '000000'])->json('detail'))
        ->toBe(__('auth.2fa.no_pending'));
});

it('AC-03: a recovery code signs in ONCE, reports the remaining count, and never works again', function (): void {
    [$tenantId, $user, , $codes] = challengeUser();

    $first = test()->withSession(pendingStateFor($tenantId, $user))
        ->postJson('/login/two-factor', ['recovery_code' => $codes[0]]);

    $first->assertOk();
    expect($first->json('recovery_codes_remaining'))->toBe(7)
        ->and($first->json('recovery_codes_low'))->toBeFalse();

    // Replay on a fresh pending state: the code is SPENT (used_at, not
    // deleted — BR-02) and must never verify again.
    $guard = auth('staff');
    if ($guard instanceof Illuminate\Auth\SessionGuard) {
        $guard->forgetUser();
    }
    test()->flushSession();
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $replay = test()->withSession(pendingStateFor($tenantId, $user))
        ->postJson('/login/two-factor', ['recovery_code' => $codes[0]]);

    $replay->assertStatus(422);
    expect(auth('staff')->user())->toBeNull('SEV-1 (AC-03): a recovery code was accepted TWICE');
});

it('R-4: the ≤2-codes banner flag comes from the store, after real consumption', function (): void {
    [$tenantId, $user, , $codes] = challengeUser();

    // Spend five codes at the store level (the challenge route proves one
    // consumption end-to-end above; this is about the count's source).
    $spent = asTenant($tenantId, function () use ($user, $codes): int {
        $store = app(RecoveryCodeStore::class);
        $n = 0;
        foreach (array_slice($codes, 0, 5) as $code) {
            $n += $store->consume($user, (string) $code, CarbonImmutable::now()) ? 1 : 0;
        }

        return $n;
    });
    expect($spent)->toBe(5, 'positive control: five distinct codes must consume');

    $sixth = test()->withSession(pendingStateFor($tenantId, $user))
        ->postJson('/login/two-factor', ['recovery_code' => $codes[5]]);

    $sixth->assertOk();
    expect($sixth->json('recovery_codes_remaining'))->toBe(2)
        ->and($sixth->json('recovery_codes_low'))->toBeTrue('FR-07: at 2 remaining the banner must prompt regeneration');
});

it('a user DISABLED mid-challenge is refused by the same pipe the password door uses', function (): void {
    [$tenantId, $user, $secret] = challengeUser();

    asTenant($tenantId, fn () => $user->forceFill(['disabled_at' => CarbonImmutable::now()])->save());

    $response = test()->withSession(pendingStateFor($tenantId, $user))
        ->postJson('/login/two-factor', ['code' => totpNow($secret)]);

    // The CODE was right; the PRINCIPAL is blocked (FR-05). And no session.
    $response->assertStatus(422);
    expect(auth('staff')->user())->toBeNull();
});

it('a challenge with no pending state is told to start with the password', function (): void {
    challengeUser();

    $response = test()->postJson('/login/two-factor', ['code' => '123456']);

    $response->assertStatus(422);
    expect($response->json('detail'))->toBe(__('auth.2fa.no_pending'));
});

it('demands exactly one proof: both or neither is a validation error, not a guess', function (): void {
    [$tenantId, $user, $secret, $codes] = challengeUser();

    test()->withSession(pendingStateFor($tenantId, $user))
        ->postJson('/login/two-factor', [])->assertStatus(422);

    test()->withSession(pendingStateFor($tenantId, $user))
        ->postJson('/login/two-factor', ['code' => totpNow($secret), 'recovery_code' => (string) $codes[0]])
        ->assertStatus(422);
});
