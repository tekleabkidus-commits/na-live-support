<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\Sessions\SessionLifetimePolicy;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The §8 login card's server half (Doc 02 §8, FR-11, AC-01; step 13).
 *
 * WHAT THIS SUITE CAN AND CANNOT SEE. It proves the card's PROPS — the
 * contract between controller and screen: the pending-challenge re-entry, the
 * FR-11 expired notice and return URL (with the ruled refusal fixtures), and
 * the non-enumeration property that the props carry nothing account-shaped.
 * The rendered STATES (spinner, focus, auto-submit) belong to the browser
 * suite; a PHP test asserting on Vue behaviour would be asserting on nothing.
 */
function loginPageUser(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create());
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    return [(string) $tenant->getKey(), $user];
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders the card for a guest with nothing account-shaped in the props (AC-01 extended to the screen)', function (): void {
    $response = test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->get('https://localhost/login');

    $response->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('Auth/Login', shouldExist: false)
            ->where('challengePending', false)
            ->where('sessionExpired', false)
            ->where('returnTo', '/')
            ->has('copy')
            // The card renders identically for every visitor: no email, no
            // account, no candidate count may ever ride these props.
            ->missing('email')
            ->missing('user')
            ->missing('accounts'),
    );
});

it('redirects an already-authenticated user away from the card instead of re-authenticating', function (): void {
    [, $user] = loginPageUser();

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->actingAs($user)->get('https://localhost/login')->assertRedirect('/');
});

it('re-enters the 2FA step on reload while a challenge is pending (§8 — the step is a card state)', function (): void {
    [$tenantId, $user] = loginPageUser();

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->withSession([
        '2fa.pending_user_id' => $user->identifier(),
        '2fa.pending_tenant_id' => $tenantId,
    ])->get('https://localhost/login')->assertInertia(
        fn ($page) => $page->component('Auth/Login', shouldExist: false)
            ->where('challengePending', true),
    );
});

it('shows the FR-11 expired notice exactly when the lifetime middleware flagged it', function (): void {
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->withSession(['auth.session_expired' => true])
        ->get('https://localhost/login')
        ->assertInertia(fn ($page) => $page->where('sessionExpired', true));

    test()->flushSession();

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->get('https://localhost/login')
        ->assertInertia(fn ($page) => $page->where('sessionExpired', false));
});

it('walks FR-11 end to end: expiry redirects to the card, flags the notice, and preserves the return URL', function (): void {
    [, $user] = loginPageUser();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03T12:00:00Z'));

    // Absolute limit exceeded: even a just-active session dies at 72 h.
    $response = test()->actingAs($user)->withSession([
        SessionLifetimePolicy::ESTABLISHED_AT => CarbonImmutable::now()->subHours(100)->toIso8601String(),
        SessionLifetimePolicy::LAST_ACTIVITY_AT => CarbonImmutable::now()->subMinute()->toIso8601String(),
    ])->get('https://localhost/security');

    $response->assertRedirect('https://localhost/login');

    expect(session()->get('auth.session_expired'))->toBeTrue(
        'FR-11: expiry did not flag the "session expired" notice in the fresh session.',
    );
    expect(session()->get('url.intended'))->toBe('https://localhost/security');

    // The card then reads both back — and the same-origin absolute the
    // framework stored comes out as a local path, not a refused '/'.
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->get('https://localhost/login')->assertInertia(
        fn ($page) => $page
            ->where('sessionExpired', true)
            ->where('returnTo', '/security'),
    );
});

it('refuses the ruled return-URL shapes through the real page (owner ruling: the guard sits on the login flow)', function (string $planted): void {
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->withSession(['url.intended' => $planted])
        ->get('https://localhost/login')
        ->assertInertia(fn ($page) => $page->where('returnTo', '/'));
})->with([
    'absolute URL' => 'https://evil.example/security',
    'protocol-relative' => '//evil.example',
    'backslash variant /\\' => '/\\evil.example',
    'backslash variant \\/' => '\\/evil.example',
]);

it('POSITIVE CONTROL: a legitimate stored return path survives the guard', function (): void {
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->withSession(['url.intended' => '/security?tab=sessions'])
        ->get('https://localhost/login')
        ->assertInertia(fn ($page) => $page->where('returnTo', '/security?tab=sessions'));
});

it('carries EC-01\'s time-sync help sentence into the challenge copy, en and am (S19 step 0)', function (): void {
    [$tenantId, $user] = loginPageUser();

    // The prop rides the same copy chain as every other challenge string —
    // asserted against the translator, not against a literal, so a key rename
    // on either side goes red here.
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->withSession([
        '2fa.pending_user_id' => $user->identifier(),
        '2fa.pending_tenant_id' => $tenantId,
    ])->get('https://localhost/login')->assertInertia(
        fn ($page) => $page
            ->where('challengePending', true)
            ->where('copy.timeSyncHelp', __('auth.2fa.challenge_time_sync_help')),
    );

    // Both launch languages resolve the key (C-08), to two different real
    // sentences — an untranslated key would echo itself back and fail here.
    $en = trans('auth.2fa.challenge_time_sync_help', [], 'en');
    $am = trans('auth.2fa.challenge_time_sync_help', [], 'am');

    expect($en)->toBeString()->not->toBe('auth.2fa.challenge_time_sync_help')
        ->and($en)->toContain('clock')
        ->and($am)->toBeString()->not->toBe('auth.2fa.challenge_time_sync_help')
        ->and($am)->not->toBe($en);
});
