<?php

declare(strict_types=1);

use App\Domain\Auth\Password\ActionTokenRedeemer;
use App\Domain\Auth\Password\TokenPurpose;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Support\Auth\Sessions\SessionRegistry;
use App\Support\Auth\Sessions\TenantPrefixedSessionHandler;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * FR-16 end to end over HTTP, plus the clauses that hang off it:
 * EC-11's victim path THROUGH THE REAL FLOW (S5 proved it at the unit
 * boundary; this is the HTTP promotion), ruling R-6's per-account unlock,
 * EC-03's any-device redemption, and FR-14's authenticated self-change.
 *
 * Every "the flow refuses X" test sits next to the positive control that the
 * same flow, without X, succeeds — the S4 lesson (two broken responses are
 * byte-identical) applies to every assertion in this file.
 */
function resetUser(string $email = 'reset@fixture.example', array $overrides = []): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create(array_merge([
        'email' => $email,
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ], $overrides)));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $user];
}

function resetLogin(string $email, string $password, ?string $ip = null): Illuminate\Testing\TestResponse
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip ?? '203.0.113.'.random_int(2, 250)])
        ->postJson('https://localhost/login', ['email' => $email, 'password' => $password]);
}

function requestReset(string $email, ?string $ip = null): Illuminate\Testing\TestResponse
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip ?? '198.51.100.'.random_int(2, 250)])
        ->postJson('https://localhost/forgot-password', ['email' => $email]);
}

/**
 * @return list<string> plaintext tokens, in queue order
 */
function queuedResetTokens(): array
{
    $tokens = [];

    Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$tokens): bool {
        if (preg_match('/token=([0-9a-f]{64})/', $mail->resetUrl, $matches) === 1) {
            $tokens[] = $matches[1];
        }

        return true;
    });

    return $tokens;
}

function submitReset(string $token, string $password): Illuminate\Testing\TestResponse
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.random_int(2, 250)])
        ->postJson('https://localhost/reset-password', [
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
}

beforeEach(function (): void {
    Mail::fake();
    Http::fake(['*' => Http::response('', 200)]); // breach bucket: empty
});

it('runs the whole FR-16 journey: request → email → new password → old dead, new works', function (): void {
    [$tenant, $user] = resetUser();

    requestReset('reset@fixture.example')->assertOk()->assertExactJson(['status' => 'accepted']);

    $tokens = queuedResetTokens();
    expect($tokens)->toHaveCount(1);

    // The form renders on a live token (EC-03 in passing: this "device" shares
    // nothing with the requester — no cookie, no session, a different IP).
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->get('https://localhost/reset-password?token='.$tokens[0])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/ResetPassword', shouldExist: false));

    submitReset($tokens[0], 'A-Brand-New-Passphrase')->assertOk()->assertExactJson(['status' => 'password_reset']);

    // The positive control everything else in this file leans on — and the
    // proof the reset half of the credential story is real, not just green.
    resetLogin('reset@fixture.example', 'A-Brand-New-Passphrase')->assertOk();

    // Shed the just-established principal before dialling again: a login door
    // behind guest:staff answers an AUTHENTICATED client with a redirect, and
    // a 302-vs-422 comparison would pass for the wrong reason.
    auth('staff')->forgetUser();
    test()->flushSession();
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    resetLogin('reset@fixture.example', 'the-correct-password')->assertStatus(422);

    // The trail (FR-16's two §4.8 events, audited per account, in-tenant).
    $trail = asTenant($tenant, fn (): array => DB::table('audit_log')
        ->whereIn('action_key', ['auth.password.reset_requested', 'auth.password.reset_completed'])
        ->pluck('action_key')->all());
    expect($trail)->toContain('auth.password.reset_requested')
        ->and($trail)->toContain('auth.password.reset_completed');

    $events = asTenant($tenant, fn (): array => DB::table('outbox')
        ->whereIn('name', ['auth.password.reset_requested', 'auth.password.reset_completed'])
        ->pluck('name')->all());
    expect($events)->toContain('auth.password.reset_requested')
        ->and($events)->toContain('auth.password.reset_completed');
});

it('keeps the token ALIVE through a policy failure and kills it on success — reuse then refused', function (): void {
    resetUser();

    requestReset('reset@fixture.example');
    [$token] = queuedResetTokens();

    // Policy refuses (too short), naming the rule; the link must survive for
    // the retry the user is about to make.
    $refused = submitReset($token, 'short');
    $refused->assertStatus(422);
    expect($refused->json('code'))->toBe('password_policy_violation')
        ->and($refused->json('errors'))->toHaveKey('length');

    // The retry works — the single-use point is the MUTATION, not the attempt.
    submitReset($token, 'A-Brand-New-Passphrase')->assertOk();

    // And now it is spent: replay refused, form replaced by the dead-link
    // screen (FR-16's dedicated error state).
    $replay = submitReset($token, 'Another-Passphrase-Entirely');
    $replay->assertStatus(422);
    expect($replay->json('code'))->toBe('action_link_invalid');

    test()->get('https://localhost/reset-password?token='.$token)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/LinkDead', shouldExist: false));

    // The dead-link body never says WHY the link is dead (SEC-01): the same
    // screen renders for a token that never existed.
    test()->get('https://localhost/reset-password?token='.str_repeat('a', 64))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/LinkDead', shouldExist: false));
});

it('revokes every session on completion (FR-16\'s "all sessions revoked")', function (): void {
    [$tenant, $user] = resetUser();
    $tenantId = $tenant->getKey();

    // Two live sessions, planted the way the registry sees real ones.
    $connection = config('session.connection');
    $redis = app(RedisFactory::class)->connection(is_string($connection) ? $connection : 'default');
    foreach (['stolen-session-1', 'stolen-session-2'] as $sessionId) {
        $redis->setex(TenantPrefixedSessionHandler::keyFor($tenantId, $sessionId), 3600, 'planted');
        app(SessionRegistry::class)->register($tenantId, $user->identifier(), $sessionId, CarbonImmutable::now());
    }
    expect(app(SessionRegistry::class)->liveSessionsFor($tenantId, $user->identifier()))->toHaveCount(2);

    requestReset('reset@fixture.example');
    [$token] = queuedResetTokens();
    submitReset($token, 'A-Brand-New-Passphrase')->assertOk();

    // Storage keys gone, not just index entries — the registry lesson from S9:
    // assert on session KEYS, never the bookkeeping.
    expect((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenantId, 'stolen-session-1')))->toBeFalse()
        ->and((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenantId, 'stolen-session-2')))->toBeFalse()
        ->and(app(SessionRegistry::class)->liveSessionsFor($tenantId, $user->identifier()))->toBe([]);
});

it('unlocks EXACTLY the account the token was bound to — R-6, with the sibling still locked and the attacker still throttled', function (): void {
    // One address, two workspaces (the multi-candidate shape from S5).
    $tenantA = createTenant();
    $userA = asTenant($tenantA, fn (): User => User::factory()->create([
        'email' => 'shared@fixture.example',
        'password' => Hash::make('password-for-tenant-a'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenantA);

    $tenantB = createTenant();
    $userB = asTenant($tenantB, fn (): User => User::factory()->create([
        'email' => 'shared@fixture.example',
        'password' => Hash::make('password-for-tenant-b'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenantB);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // The attacker locks the address from one IP (multi-candidate: BOTH lock).
    $attackerIp = '203.0.113.66';
    foreach (range(1, 5) as $ignored) {
        resetLogin('shared@fixture.example', 'wrong-password', $attackerIp);
    }

    $rows = collect(DB::select('SELECT * FROM resolve_staff_login(?)', ['shared@fixture.example']))->keyBy('user_id');
    expect($rows[$userA->getKey()]->locked_until)->not->toBeNull()
        ->and($rows[$userB->getKey()]->locked_until)->not->toBeNull();

    $throttleKey = md5('auth-ip'.$attackerIp);
    $attackerHitsBefore = app(RateLimiter::class)->attempts($throttleKey);
    expect($attackerHitsBefore)->toBeGreaterThanOrEqual(5);

    // The victim resets — EC-11's guaranteed path, which must not require the
    // attacker to stop. One request mints one token PER account…
    requestReset('shared@fixture.example');
    $tokens = queuedResetTokens();
    expect($tokens)->toHaveCount(2);

    // …and completing tenant A's token unlocks tenant A's account alone.
    $redeemer = app(ActionTokenRedeemer::class);
    $tokenA = collect($tokens)->first(
        fn (string $token): bool => $redeemer->resolve($token, TokenPurpose::PasswordReset)?->tenantId === $tenantA->getKey(),
    );
    expect($tokenA)->not->toBeNull();

    submitReset((string) $tokenA, 'A-Fresh-Tenant-A-Password')->assertOk();

    $after = collect(DB::select('SELECT * FROM resolve_staff_login(?)', ['shared@fixture.example']))->keyBy('user_id');
    expect($after[$userA->getKey()]->locked_until)->toBeNull('the reset account must unlock')
        ->and($after[$userA->getKey()]->failed_login_attempts)->toEqual(0)
        // R-6's second half: the SIBLING stays locked — an unlock in tenant B
        // that tenant B's audit trail cannot explain must never happen.
        ->and($after[$userB->getKey()]->locked_until)->not->toBeNull('the sibling account must stay locked');

    // …and the account works immediately (EC-11, now through the real flow).
    resetLogin('shared@fixture.example', 'A-Fresh-Tenant-A-Password')->assertOk();

    // The attacker's per-IP budget is untouched by the victim's recovery: the
    // reset path never writes to the limiter, in either direction.
    expect(app(RateLimiter::class)->attempts($throttleKey))->toBe($attackerHitsBefore);
});

it('mints nothing for invited or disabled accounts, behind the identical response', function (): void {
    resetUser('invited@fixture.example', ['password_set_at' => null]);
    resetUser('disabled@fixture.example', ['disabled_at' => now()]);

    $invited = requestReset('invited@fixture.example');
    $disabled = requestReset('disabled@fixture.example');
    $unknown = requestReset('nobody@fixture.example');

    Mail::assertNothingQueued();

    // The three refusal shapes are one shape (SEC-01) — byte-level identity
    // is the enumeration suite's job; equality here is the flow-level echo.
    expect($invited->getContent())->toBe($unknown->getContent())
        ->and($disabled->getContent())->toBe($unknown->getContent());
});

it('changes the password from a live session with FR-14\'s keep-current revocation', function (): void {
    [$tenant, $user] = resetUser('change@fixture.example');
    $tenantId = $tenant->getKey();

    // A second device's session, which FR-14 says must die on change.
    $connection = config('session.connection');
    $redis = app(RedisFactory::class)->connection(is_string($connection) ? $connection : 'default');
    $redis->setex(TenantPrefixedSessionHandler::keyFor($tenantId, 'other-device'), 3600, 'planted');
    app(SessionRegistry::class)->register($tenantId, $user->identifier(), 'other-device', CarbonImmutable::now());

    // Wrong current password: refused, nothing revoked, nothing changed.
    test()->flushSession();
    $refused = test()->actingAs($user)->putJson('/api/staff/security/password', [
        'current_password' => 'not-the-current-password',
        'password' => 'A-Fresh-Change-Password',
        'password_confirmation' => 'A-Fresh-Change-Password',
    ]);
    $refused->assertStatus(422);
    expect($refused->json('errors'))->toHaveKey('current_password')
        ->and((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenantId, 'other-device')))->toBeTrue();

    // Right current password: applied; the OTHER session dies (FR-14), the
    // change is audited and published.
    test()->actingAs($user)->putJson('/api/staff/security/password', [
        'current_password' => 'the-correct-password',
        'password' => 'A-Fresh-Change-Password',
        'password_confirmation' => 'A-Fresh-Change-Password',
    ])->assertOk();

    expect((bool) $redis->exists(TenantPrefixedSessionHandler::keyFor($tenantId, 'other-device')))->toBeFalse();

    // Shed the actingAs() principal and BOTH tenancy layers before dialling
    // the login door as a stranger — ambient session state is invisible in a
    // green run (the S1 lesson, verbatim from BUILD-STATUS).
    auth('staff')->forgetUser();
    test()->flushSession();
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    resetLogin('change@fixture.example', 'A-Fresh-Change-Password')->assertOk();

    $trail = asTenant($tenant, fn (): array => DB::table('audit_log')
        ->where('action_key', 'auth.password.changed')->pluck('action_key')->all());
    expect($trail)->not->toBeEmpty();
});
