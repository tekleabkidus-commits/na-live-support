<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\Sessions\TenantHint;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * FR-13's recaller path — the login-via-recaller itself (the S18 audit's named
 * gap, closed at the S19 step-0 housekeeping).
 *
 * What was already proven: the cookie is withheld until authentication is
 * COMPLETE, honoured only at establishment, and never cycled at expiry. What
 * nothing executed until now is the path the cookie exists FOR: the browser
 * returns with a dead session and a live remember cookie, and the guard signs
 * the user back in from it.
 *
 * HARNESS SHAPE. The PHP client never replays cookies between requests (the S6
 * lesson) — which is exactly the fixture this test needs: a second request
 * carrying ONLY the cookies we explicitly attach, like a browser whose session
 * cookie has expired. Both durable cookies are captured from the real login
 * response and replayed encrypted, exactly as a browser would return them:
 * the recaller itself, and the tenant hint the pre-session user read requires
 * (StaffUserProvider::retrieveById — no hint, no lookup, no session).
 */
function recallerCookiesFromLogin(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'recaller@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenant);

    $login = test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])
        ->post('https://localhost/login', [
            'email' => 'recaller@fixture.example',
            'password' => 'the-correct-password',
            'remember' => true,
        ]);

    $guard = auth('staff');
    expect($guard)->toBeInstanceOf(SessionGuard::class);
    /** @var SessionGuard $guard */
    $recallerName = $guard->getRecallerName();

    $cookies = collect($login->baseResponse->headers->getCookies());
    $recaller = $cookies->first(fn (Cookie $c): bool => $c->getName() === $recallerName);
    $hint = $cookies->first(fn (Cookie $c): bool => $c->getName() === TenantHint::COOKIE);

    expect($recaller)->not->toBeNull('FR-13: a remembered login issued no remember cookie.');
    expect($hint)->not->toBeNull('The login response carried no tenant-hint cookie.');

    // Kill every trace of the logged-in request: in-memory guard user, bound
    // tenant, and the session store. What survives is what a browser would
    // still hold a week later — the two durable cookies above.
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();
    $guard->forgetUser();

    return [$user, $recallerName, $recaller, $hint];
}

it('signs the user back in from the remember cookie when the session is gone (FR-13 — the recaller path)', function (): void {
    [$user, $recallerName, $recaller, $hint] = recallerCookiesFromLogin();

    $response = test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])
        ->withUnencryptedCookie($recallerName, (string) $recaller->getValue())
        ->withUnencryptedCookie(TenantHint::COOKIE, (string) $hint->getValue())
        ->get('https://localhost/security');

    $response->assertOk();

    $guard = auth('staff');
    /** @var SessionGuard $guard */
    $authenticated = $guard->user();

    expect($authenticated)->toBeInstanceOf(User::class);
    /** @var User $authenticated */
    expect($authenticated->identifier())->toBe($user->identifier());
    expect($guard->viaRemember())->toBeTrue(
        'FR-13: the request authenticated, but not via the recaller — this test proved nothing.',
    );
});

it('NEGATIVE CONTROL: a recaller whose token no longer matches the stored one authenticates nobody', function (): void {
    [$user, $recallerName, $recaller, $hint] = recallerCookiesFromLogin();

    // The stored token rotates out from under the cookie — the state FR-14's
    // revocations produce. hash_equals in retrieveByToken must refuse it.
    asTenant($user->tenantId(), function () use ($user): void {
        $user->setRememberToken(str_repeat('0', 60));
        $user->save();
    });
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $response = test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])
        ->withUnencryptedCookie($recallerName, (string) $recaller->getValue())
        ->withUnencryptedCookie(TenantHint::COOKIE, (string) $hint->getValue())
        ->get('https://localhost/security');

    $response->assertRedirect('https://localhost/login');

    $guard = auth('staff');
    expect($guard->user())->toBeNull(
        'A stale remember token authenticated a user — retrieveByToken accepted a non-matching token.',
    );
});
