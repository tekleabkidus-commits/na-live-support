<?php

declare(strict_types=1);

use App\Domain\Auth\Password\ActionTokenIssuer;
use App\Domain\Auth\Password\ActionTokenRedeemer;
use App\Domain\Auth\Password\TokenPurpose;
use App\Models\AuthActionToken;
use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * SEC-02, clause by clause: "Reset/invite tokens: 256-bit, single-use, hashed
 * at rest, bound to email + purpose" — plus EC-04's resend physics.
 *
 * Every negative case here has a POSITIVE CONTROL beside it (the same token
 * resolving successfully before the property under test kills it), because a
 * resolve() that returns null for the wrong reason — a broken door, a missing
 * fixture — is indistinguishable from a working refusal (the S6 lesson, three
 * times over by now).
 */
function actionTokenUser(string $email = 'tokens@fixture.example'): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => $email,
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $user];
}

function issueToken(object $tenant, User $user, TokenPurpose $purpose = TokenPurpose::PasswordReset, ?CarbonImmutable $now = null): string
{
    $now ??= CarbonImmutable::now();
    $expires = $purpose === TokenPurpose::PasswordReset ? $now->addMinutes(60) : $now->addDays(7);

    return app(ActionTokenIssuer::class)->issue(
        $tenant->getKey(),
        $user->identifier(),
        $purpose,
        (string) $user->getAttribute('email'),
        $now,
        $expires,
    );
}

it('issues a 256-bit token, stores only its SHA-256, and resolves it back to the right account', function (): void {
    [$tenant, $user] = actionTokenUser();

    $plaintext = issueToken($tenant, $user);

    // 32 random bytes as 64 hex chars — SEC-02's 256 bits, in the only shape
    // the issuer produces and the redeemer accepts.
    expect($plaintext)->toMatch('/^[0-9a-f]{64}$/');

    // Hashed at rest: the stored value is the SHA-256, and the plaintext
    // appears nowhere in the row (the no-plaintext-column half is schema
    // physics, already pinned by GrantPhysicsTest).
    $row = asTenant($tenant, fn () => AuthActionToken::query()->firstOrFail());
    expect($row->token_hash)->toBe(hash('sha256', $plaintext))
        ->and($row->sent_to_email)->toBe('tokens@fixture.example');

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // The positive control every later refusal leans on: the door answers
    // with NO tenant bound, and the redemption names the right account.
    $redemption = app(ActionTokenRedeemer::class)->resolve($plaintext, TokenPurpose::PasswordReset);

    expect($redemption)->not->toBeNull()
        ->and($redemption->userId)->toBe($user->identifier())
        ->and($redemption->tenantId)->toBe($tenant->getKey())
        ->and($redemption->purpose)->toBe(TokenPurpose::PasswordReset);
});

it('refuses cross-purpose redemption both ways (bound to purpose)', function (): void {
    [$tenant, $user] = actionTokenUser();

    $reset = issueToken($tenant, $user, TokenPurpose::PasswordReset);
    $invite = issueToken($tenant, $user, TokenPurpose::Invitation);

    $redeemer = app(ActionTokenRedeemer::class);

    // Positive controls: each token works at its OWN door.
    expect($redeemer->resolve($reset, TokenPurpose::PasswordReset))->not->toBeNull()
        ->and($redeemer->resolve($invite, TokenPurpose::Invitation))->not->toBeNull();

    // The escalation SEC-02 forbids: a 7-day invite token acting as a 60-min
    // reset token, or a reset token activating an account.
    expect($redeemer->resolve($reset, TokenPurpose::Invitation))->toBeNull()
        ->and($redeemer->resolve($invite, TokenPurpose::PasswordReset))->toBeNull();
});

it('is single-use: consume() wins exactly once, and a consumed token stops resolving', function (): void {
    [$tenant, $user] = actionTokenUser();

    $plaintext = issueToken($tenant, $user);
    $redeemer = app(ActionTokenRedeemer::class);

    $redemption = $redeemer->resolve($plaintext, TokenPurpose::PasswordReset);
    expect($redemption)->not->toBeNull();

    $now = CarbonImmutable::now();

    // The compare-and-set: first spend wins, the replay loses — this pair is
    // what makes "single-use under concurrency" a tested property rather than
    // a hope about request interleaving.
    expect($redeemer->consume($redemption, $now))->toBeTrue()
        ->and($redeemer->consume($redemption, $now))->toBeFalse()
        ->and($redeemer->resolve($plaintext, TokenPurpose::PasswordReset))->toBeNull();
});

it('dies when the account email changes after issuance (bound to email)', function (): void {
    [$tenant, $user] = actionTokenUser();

    $plaintext = issueToken($tenant, $user);
    $redeemer = app(ActionTokenRedeemer::class);

    expect($redeemer->resolve($plaintext, TokenPurpose::PasswordReset))->not->toBeNull();

    asTenant($tenant, function () use ($user): void {
        $user->setAttribute('email', 'renamed@fixture.example');
        $user->save();
    });
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // SEC-02's point: when "someone changed the email" is the incident in
    // progress, every outstanding link for the old address is already dead.
    expect($redeemer->resolve($plaintext, TokenPurpose::PasswordReset))->toBeNull();
});

it('dies at expiry, two-sided at the DB-time boundary', function (): void {
    [$tenant, $user] = actionTokenUser();

    $plaintext = issueToken($tenant, $user);
    $redeemer = app(ActionTokenRedeemer::class);

    // Alive with expiry in the future (the near side of the boundary).
    asTenant($tenant, fn (): int => AuthActionToken::query()->update(['expires_at' => DB::raw("now() + interval '1 minute'")]));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    expect($redeemer->resolve($plaintext, TokenPurpose::PasswordReset))->not->toBeNull();

    // Dead one second past it. The comparison is the DOOR's, at DB time
    // (C-11), so the boundary is moved in DATA rather than by faking a clock
    // the SQL function would not read anyway.
    asTenant($tenant, fn (): int => AuthActionToken::query()->update(['expires_at' => DB::raw("now() - interval '1 second'")]));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    expect($redeemer->resolve($plaintext, TokenPurpose::PasswordReset))->toBeNull();
});

it('supersedes the previous live token on reissue — EC-04 as physics, not memory', function (): void {
    [$tenant, $user] = actionTokenUser();
    $redeemer = app(ActionTokenRedeemer::class);

    $first = issueToken($tenant, $user);
    expect($redeemer->resolve($first, TokenPurpose::PasswordReset))->not->toBeNull();

    $second = issueToken($tenant, $user);

    // The resend invalidated the old link; only the newest acts.
    expect($redeemer->resolve($first, TokenPurpose::PasswordReset))->toBeNull()
        ->and($redeemer->resolve($second, TokenPurpose::PasswordReset))->not->toBeNull();

    // And the physics that make the supersede impossible to forget: a second
    // LIVE row for the same (user, purpose) is refused by the partial unique
    // index itself — remove the issuer's stamping and the insert fails here.
    // Inside DB::transaction so the violation dies at a SAVEPOINT instead of
    // aborting the test's own wrapping transaction (Postgres 25P02).
    asTenant($tenant, function () use ($user): void {
        expect(fn () => DB::transaction(fn () => AuthActionToken::query()->create([
            'user_id' => $user->identifier(),
            'purpose' => TokenPurpose::PasswordReset->value,
            'token_hash' => hash('sha256', 'a-forged-second-live-token'),
            'sent_to_email' => 'tokens@fixture.example',
            'expires_at' => CarbonImmutable::now()->addHour(),
        ])))->toThrow(Illuminate\Database\QueryException::class);
    });
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
});

it('refuses garbage shapes without touching the door', function (): void {
    [$tenant, $user] = actionTokenUser();
    issueToken($tenant, $user); // a live token exists, so null cannot mean "empty table"

    $redeemer = app(ActionTokenRedeemer::class);

    foreach (['', 'short', str_repeat('g', 64), strtoupper(str_repeat('a', 64)), str_repeat('a', 63)] as $garbage) {
        expect($redeemer->resolve($garbage, TokenPurpose::PasswordReset))->toBeNull();
    }
});

it('refuses a token whose owner was disabled after issuance', function (): void {
    [$tenant, $user] = actionTokenUser();

    $plaintext = issueToken($tenant, $user);
    $redeemer = app(ActionTokenRedeemer::class);

    expect($redeemer->resolve($plaintext, TokenPurpose::PasswordReset))->not->toBeNull();

    // BR-04: disabling is an administrative decision a reset link must not
    // outlive — the disabled check runs on the CURRENT row, not issuance state.
    asTenant($tenant, function () use ($user): void {
        $user->setAttribute('disabled_at', CarbonImmutable::now());
        $user->save();
    });
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    expect($redeemer->resolve($plaintext, TokenPurpose::PasswordReset))->toBeNull();
});
