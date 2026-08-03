<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * I-5 — the per-request user-locale hop (Doc 01 inherited task; Doc 02 §14, C-08).
 *
 * The observable is a REAL localized response body, not app()->getLocale():
 * `GET /api/staff/settings/{unregistered}` renders problem-details whose
 * `detail` is `__('foundation.error.unregistered_setting')`, which differs
 * between en and am — and the first test PROVES it differs, because two
 * identical translations would make every assertion below vacuously green.
 *
 * The middle of the C-08 chain (tenant default) and the final hop (en) are
 * TelemetricTranslator's and are proven by I18nFallbackTest; this suite proves
 * only the hop that middleware owns — users.locale becoming the request locale
 * — and its Octane restore.
 */
function localeUser(?string $locale): User
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create(['locale' => $locale]));
    activateTenant($tenant);

    return $user;
}

function unknownSettingDetail(User $user): string
{
    // Fixtures deliberately leave a tenant bound (see tests/Pest.php on
    // asTenant), and the previous request in a multi-request test leaves ITS
    // session behind — a request must start the way a real one does: no ambient
    // tenant, no ambient session. This is the S1 lesson from BUILD-STATUS:
    // ambient state is invisible in a green run.
    app(App\Support\Tenancy\TenantContext::class)->clear();
    app(App\Support\Tenancy\RlsSession::class)->clear();
    test()->flushSession();

    $response = test()->actingAs($user)->getJson('/api/staff/settings/tenant.nonsense');
    $response->assertNotFound();

    $detail = $response->json('detail');
    expect($detail)->toBeString();

    return $detail;
}

it('positive control: the en and am strings this suite compares actually differ', function (): void {
    $en = trans('foundation.error.unregistered_setting', locale: 'en');
    $am = trans('foundation.error.unregistered_setting', locale: 'am');

    expect($en)->toBeString()
        ->and($am)->toBeString()
        ->and($en)->not->toBe($am, 'the sentinel string is identical in en and am — every locale assertion in this suite would pass no matter what the middleware does');
});

it('serves a locale-less user in English (the chain\'s final hop, unchanged)', function (): void {
    expect(unknownSettingDetail(localeUser(null)))
        ->toBe(trans('foundation.error.unregistered_setting', locale: 'en'));
});

it('I-5: a user whose locale is am is served Amharic on an ordinary staff request', function (): void {
    expect(unknownSettingDetail(localeUser('am')))
        ->toBe(
            trans('foundation.error.unregistered_setting', locale: 'am'),
            'SEV-1 for §14: users.locale did not become the request locale — the C-08 chain\'s first hop never fires and an agent\'s language choice means nothing',
        );
});

it('restores the worker locale after the request (the Octane leak, Doc 01 R-01 shape)', function (): void {
    // Two sequential requests through ONE application instance — exactly what
    // an Octane worker is. If the middleware set the locale and never restored
    // it, the second user inherits the first user's language and this test is
    // the only place that can see it: every single-request test stays green.
    $amUser = localeUser('am');
    $plainUser = localeUser(null);

    expect(unknownSettingDetail($amUser))
        ->toBe(trans('foundation.error.unregistered_setting', locale: 'am'), 'positive control: the first request must be served in Amharic for the leak half to mean anything');

    expect(app()->getLocale())
        ->toBe('en', 'SEV-1: the worker locale is still the previous user\'s after the response was sent');

    expect(unknownSettingDetail($plainUser))
        ->toBe(
            trans('foundation.error.unregistered_setting', locale: 'en'),
            'SEV-1: a locale-less user was served the PREVIOUS user\'s language — request state leaked across requests on one worker',
        );
});

it('ignores a malformed users.locale instead of dying or traversing', function (): void {
    // users.locale feeds the translation FileLoader's path. The shape check in
    // the middleware is what stands between a bad row and either a 500 on every
    // request or a hostile path — this asserts the skip, in the shape FS-04
    // uses elsewhere: degrade, never break the request.
    expect(unknownSettingDetail(localeUser('../am')))
        ->toBe(trans('foundation.error.unregistered_setting', locale: 'en'));
});

// ── Step 13: the guest cookie hop (§8's language switcher; owner ruling). ────

it('guest cookie hop: locale=am renders the login card in Amharic; en and am titles actually differ', function (): void {
    $en = trans('auth.login.title', locale: 'en');
    $am = trans('auth.login.title', locale: 'am');
    expect($en)->not->toBe($am, 'the sentinel string is identical in en and am — the cookie assertions below would be vacuous');

    // Control first: test cookies persist for the remainder of the test, so
    // the cookie-less request must come before the cookie is ever set.
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->get('https://localhost/login')
        ->assertInertia(fn ($page) => $page->where('copy.title', $en));

    test()->flushSession();

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->withUnencryptedCookie('locale', 'am')
        ->get('https://localhost/login')
        ->assertInertia(fn ($page) => $page->where('copy.title', $am));
});

it('holds the cookie to EXACT membership in {en, am} — a shape-plausible value is still refused and counted', function (): void {
    // `fr` would pass the staff value's shape check; the client-controlled
    // cookie is held to the stricter exact-set bar (owner ruling), because a
    // client string must never reach the translator's file paths on
    // pattern-match alone.
    Log::spy();

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(2, 250)])->withUnencryptedCookie('locale', 'fr')
        ->get('https://localhost/login')
        ->assertInertia(fn ($page) => $page->where('copy.title', trans('auth.login.title', locale: 'en')));

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []): bool => ($context['metric'] ?? null) === 'i18n.invalid_locale_cookie',
    );
});

it('users.locale ALWAYS beats the cookie for an authenticated request (owner ruling)', function (): void {
    expect(unknownSettingDetailWithCookie(localeUser('en'), 'am'))
        ->toBe(trans('foundation.error.unregistered_setting', locale: 'en'), 'the guest cookie overrode an authenticated user\'s own locale');
});

function unknownSettingDetailWithCookie(User $user, string $cookie): string
{
    app(App\Support\Tenancy\TenantContext::class)->clear();
    app(App\Support\Tenancy\RlsSession::class)->clear();
    test()->flushSession();

    $response = test()->actingAs($user)
        ->withUnencryptedCookie('locale', $cookie)
        ->getJson('https://localhost/api/staff/settings/tenant.nonsense');
    $response->assertNotFound();

    $detail = $response->json('detail');
    expect($detail)->toBeString();

    return $detail;
}
