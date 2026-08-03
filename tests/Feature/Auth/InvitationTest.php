<?php

declare(strict_types=1);

use App\Domain\Auth\Password\ActionTokenIssuer;
use App\Domain\Auth\Password\ActionTokenRedeemer;
use App\Domain\Auth\Password\InvitationIssuer;
use App\Domain\Auth\Password\TokenPurpose;
use App\Mail\InvitationMail;
use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * FR-17: invited state, 7-day set-password link, EC-04's self-service resend —
 * and BR-02 as a STRUCTURAL fact: no path through issuance accepts a password.
 */
function invitedUser(string $email = 'invited@fixture.example'): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => $email,
        // FR-17: the account has never had a chosen password. The hash is 64
        // random bytes nobody knows — unusable by construction; the STATE
        // marker is password_set_at NULL, per the users-table migration.
        'password' => Hash::make(bin2hex(random_bytes(32))),
        'password_set_at' => null,
    ]));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $user];
}

function inviteLogin(string $email, string $password): Illuminate\Testing\TestResponse
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])
        ->postJson('https://localhost/login', ['email' => $email, 'password' => $password]);
}

/**
 * @return list<string>
 */
function queuedInviteTokens(): array
{
    $tokens = [];

    Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use (&$tokens): bool {
        if (preg_match('/token=([0-9a-f]{64})/', $mail->inviteUrl, $matches) === 1) {
            $tokens[] = $matches[1];
        }

        return true;
    });

    return $tokens;
}

beforeEach(function (): void {
    Mail::fake();
    Http::fake(['*' => Http::response('', 200)]);
});

it('walks FR-17 end to end: invited cannot log in, sets a password once, then is an ordinary account', function (): void {
    [$tenant, $user] = invitedUser();

    // Whatever an attacker types, an invited account refuses with the SAME
    // generic body as a nonexistent address — invited state must not be
    // probeable from the login form (SEC-01).
    $invited = inviteLogin('invited@fixture.example', 'any-guess-at-all');
    $unknown = inviteLogin('nobody-here@fixture.example', 'any-guess-at-all');
    $invited->assertStatus(422);
    expect($invited->getContent())->toBe($unknown->getContent());

    // The invitation arrives…
    app(InvitationIssuer::class)->issue($user, CarbonImmutable::now(), Request::create('https://localhost/x', 'POST'));
    [$token] = queuedInviteTokens();

    test()->get('https://localhost/invitation?token='.$token)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Invitation', shouldExist: false));

    // …the invited person — and ONLY they — choose the password (BR-02).
    test()->postJson('https://localhost/invitation', [
        'token' => $token,
        'password' => 'My-Own-First-Password',
        'password_confirmation' => 'My-Own-First-Password',
    ])->assertOk()->assertExactJson(['status' => 'password_set']);

    // The account left the invited state (FR-17's one-way door)…
    $fresh = asTenant($tenant, fn () => User::query()->findOrFail($user->identifier()));
    expect($fresh->getAttribute('password_set_at'))->not->toBeNull();
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // …and works like any other (positive control), with the trail written.
    inviteLogin('invited@fixture.example', 'My-Own-First-Password')->assertOk();

    $trail = asTenant($tenant, fn (): array => DB::table('audit_log')
        ->whereIn('action_key', ['auth.invite.issued', 'auth.invite.accepted'])
        ->pluck('action_key')->all());
    expect($trail)->toContain('auth.invite.issued')
        ->and($trail)->toContain('auth.invite.accepted');

    // Shed the just-signed-in principal before presenting the link again —
    // the guest surface answers an authenticated client differently, and this
    // assertion is about the TOKEN being spent, not about redirects.
    auth('staff')->forgetUser();
    test()->flushSession();
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // The link is spent: FR-17's set-password is not a login back door.
    test()->postJson('https://localhost/invitation', [
        'token' => $token,
        'password' => 'A-Second-Attempt-Password',
        'password_confirmation' => 'A-Second-Attempt-Password',
    ])->assertStatus(422);
});

it('honours the 7-day validity, two-sided (FR-17)', function (): void {
    [$tenant, $user] = invitedUser();
    $redeemer = app(ActionTokenRedeemer::class);

    // Issued 6 days ago: alive (the issuer computes expiry from the injected
    // clock, so the boundary is data, not a sleep).
    app(InvitationIssuer::class)->issue($user, CarbonImmutable::now()->subDays(6), Request::create('https://localhost/x', 'POST'));
    [$sixDays] = queuedInviteTokens();
    expect($redeemer->resolve($sixDays, TokenPurpose::Invitation))->not->toBeNull();

    // Issued 8 days ago: dead on arrival — and it renders FR-16's shared
    // dead-link screen with EC-04's resend, not a stack trace.
    app(InvitationIssuer::class)->issue($user, CarbonImmutable::now()->subDays(8), Request::create('https://localhost/x', 'POST'));
    $tokens = queuedInviteTokens();
    $eightDays = end($tokens);

    expect($redeemer->resolve((string) $eightDays, TokenPurpose::Invitation))->toBeNull();
    test()->get('https://localhost/invitation?token='.$eightDays)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/LinkDead', shouldExist: false));
});

it('invalidates the old link on self-service resend and answers uniformly (EC-04)', function (): void {
    [, $user] = invitedUser();
    $redeemer = app(ActionTokenRedeemer::class);

    app(InvitationIssuer::class)->issue($user, CarbonImmutable::now(), Request::create('https://localhost/x', 'POST'));
    [$original] = queuedInviteTokens();
    expect($redeemer->resolve($original, TokenPurpose::Invitation))->not->toBeNull();

    // The self-service resend — requested by an UNAUTHENTICATED person who
    // only knows their address.
    $resent = test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson('https://localhost/invitation/resend', ['email' => 'invited@fixture.example']);
    $resent->assertOk();

    // Uniform against an address that matches nothing (SEC-01 names invites).
    $nothing = test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])
        ->postJson('https://localhost/invitation/resend', ['email' => 'stranger@fixture.example']);
    expect($resent->getContent())->toBe($nothing->getContent());

    // EC-04: old link invalidated, the new one works.
    $tokens = queuedInviteTokens();
    expect($tokens)->toHaveCount(2)
        ->and($redeemer->resolve($tokens[0], TokenPurpose::Invitation))->toBeNull()
        ->and($redeemer->resolve($tokens[1], TokenPurpose::Invitation))->not->toBeNull();

    // A resend for an account that already SET its password mints nothing —
    // an active account's credential is not re-issuable by anyone who knows
    // the address.
    Mail::fake();
    asTenant(createTenant(), function (): void {
        User::factory()->create([
            'email' => 'active@fixture.example',
            'password' => Hash::make('already-chosen'),
            'password_set_at' => now(),
        ]);
    });
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->postJson('https://localhost/invitation/resend', ['email' => 'active@fixture.example'])
        ->assertOk();
    Mail::assertNothingQueued();
});

it('offers no structural path for anyone to choose a password for someone else (BR-02)', function (): void {
    // The issuance surface, by reflection: no parameter on any public method
    // of the two issuing classes accepts anything password-like. This is the
    // assertion that fails the day someone adds a convenience `$password`
    // argument "for seeding".
    foreach ([InvitationIssuer::class, ActionTokenIssuer::class] as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                expect(Str::contains(strtolower($parameter->getName()), ['password', 'secret', 'hash']))
                    ->toBeFalse(sprintf(
                        '%s::%s() accepts "%s" — issuance must never carry a credential (BR-02, FR-17)',
                        $class,
                        $method->getName(),
                        $parameter->getName(),
                    ));
            }
        }
    }

    // And the throttle is the route's, not the controller's manners: the
    // resend surface sits behind the same per-IP budget as login (EC-04
    // "rate-limited"; LockoutTest proves the limiter itself).
    $route = collect(app('router')->getRoutes())->first(
        fn ($candidate): bool => $candidate->getName() === 'auth.invitation.resend',
    );
    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:auth-ip');
});

// ── Step 13: §8's profile-completion half (name, language). ─────────────────

it('completes the profile on accept: name and language persist with the first password (§8, step 13)', function (): void {
    [$tenant, $user] = invitedUser('profile-complete@fixture.example');

    app(InvitationIssuer::class)->issue($user, CarbonImmutable::now(), Request::create('https://localhost/x', 'POST'));
    [$token] = queuedInviteTokens();

    test()->postJson('https://localhost/invitation', [
        'token' => $token,
        'password' => 'My-Own-First-Password',
        'password_confirmation' => 'My-Own-First-Password',
        'name' => 'Makeda Fixture',
        'locale' => 'am',
    ])->assertOk();

    $fresh = asTenant($tenant, fn () => User::query()->findOrFail($user->identifier()));
    expect($fresh->getAttribute('name'))->toBe('Makeda Fixture')
        ->and($fresh->getAttribute('locale'))->toBe('am')
        ->and($fresh->getAttribute('password_set_at'))->not->toBeNull();
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
});

it('refuses a locale outside {en, am} loudly, spends nothing, and lets the person retry (§8, step 13)', function (): void {
    [$tenant, $user] = invitedUser('profile-locale@fixture.example');
    $originalName = asTenant($tenant, fn () => User::query()->findOrFail($user->identifier())->getAttribute('name'));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    app(InvitationIssuer::class)->issue($user, CarbonImmutable::now(), Request::create('https://localhost/x', 'POST'));
    [$token] = queuedInviteTokens();

    $refused = test()->postJson('https://localhost/invitation', [
        'token' => $token,
        'password' => 'My-Own-First-Password',
        'password_confirmation' => 'My-Own-First-Password',
        'locale' => 'fr',
    ]);
    $refused->assertStatus(422);
    expect($refused->json('errors.locale'))->not->toBeNull();

    // Nothing was spent or written: the account is still invited, the name
    // untouched, and THE SAME LINK still works — a refused profile must not
    // burn the invitation.
    $fresh = asTenant($tenant, fn () => User::query()->findOrFail($user->identifier()));
    expect($fresh->getAttribute('password_set_at'))->toBeNull()
        ->and($fresh->getAttribute('name'))->toBe($originalName);
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    test()->postJson('https://localhost/invitation', [
        'token' => $token,
        'password' => 'My-Own-First-Password',
        'password_confirmation' => 'My-Own-First-Password',
        'locale' => 'am',
    ])->assertOk();
});

it('leaves the profile untouched when the PASSWORD is refused — no unaudited write rides a failed accept', function (): void {
    [$tenant, $user] = invitedUser('profile-ordering@fixture.example');
    $originalName = asTenant($tenant, fn () => User::query()->findOrFail($user->identifier())->getAttribute('name'));
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    app(InvitationIssuer::class)->issue($user, CarbonImmutable::now(), Request::create('https://localhost/x', 'POST'));
    [$token] = queuedInviteTokens();

    test()->postJson('https://localhost/invitation', [
        'token' => $token,
        'password' => 'short',
        'password_confirmation' => 'short',
        'name' => 'Should Not Persist',
        'locale' => 'am',
    ])->assertStatus(422);

    $fresh = asTenant($tenant, fn () => User::query()->findOrFail($user->identifier()));
    expect($fresh->getAttribute('name'))->toBe($originalName, 'a policy-refused accept wrote the profile anyway — a mutation the audit declaration never covers')
        ->and($fresh->getAttribute('locale'))->not->toBe('am')
        ->and($fresh->getAttribute('password_set_at'))->toBeNull();
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
});

it('prefills the profile fields from what the admin already entered (§8: completion, not a blank slate)', function (): void {
    [, $user] = invitedUser('profile-prefill@fixture.example');

    app(InvitationIssuer::class)->issue($user, CarbonImmutable::now(), Request::create('https://localhost/x', 'POST'));
    [$token] = queuedInviteTokens();

    test()->get('https://localhost/invitation?token='.$token)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Auth/Invitation', shouldExist: false)
                ->where('profile.name', $user->getAttribute('name'))
                ->where('profile.locale', 'en'),
        );
});
