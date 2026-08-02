<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\Sessions\TenantHint;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

/**
 * SEC-04 and T-04/Q7: session fixation, and the tenant-hint cookie.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY FIXATION IS THE ATTACK THIS FILE IS ABOUT.
 *
 * Session fixation is the quiet cousin of session theft. The attacker does not
 * steal a session id — they PLANT one. Visit the login page, note the id the
 * server hands out, then get the victim to use that same id (a link, a shared
 * kiosk, an XSS-set cookie). If the server keeps the id across the privilege
 * boundary, the attacker's known id is now an authenticated session, and no
 * password was ever stolen.
 *
 * SEC-04's answer is one line and absolute: regenerate at EVERY privilege
 * boundary. Doc 02 names two — post-password and post-2FA — and this suite
 * asserts the first today; the second becomes assertable when the 2FA step lands
 * and is recorded as a gap rather than silently skipped.
 *
 * THE TENANT-HINT COOKIE (owner ruling Q7). Sessions are stored under a
 * `t:{tenant}:` prefix, but the prefix must be known BEFORE the session is
 * loaded — and the tenant is only known FROM the session. That circularity is
 * broken by a signed hint cookie written at login. The danger is obvious the
 * moment you write it down: if the hint were trusted, changing one cookie would
 * point a session lookup at another tenant's keyspace. So the ruling requires,
 * and these tests prove, that a tampered or mismatched hint is treated as NO
 * SESSION — never as a session belonging to the hinted tenant.
 * ─────────────────────────────────────────────────────────────────────────────
 */
function fixationUser(string $email = 'agent@fixture.example'): array
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

function loginAndCaptureSessionId(string $email = 'agent@fixture.example'): array
{
    // Start a guest session first, exactly as a real browser does when it loads
    // the login form — that is the id an attacker would plant.
    $before = test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->get('https://localhost/');
    $preLoginId = session()->getId();

    $response = test()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->postJson('https://localhost/login', [
            'email' => $email,
            'password' => 'the-correct-password',
        ]);

    return [$preLoginId, session()->getId(), $response];
}

/*
 | ─────────────────────────────────────────────────────────────────────────────
 | THIS TEST IS SKIPPED BECAUSE FALSIFICATION PROVED IT WORTHLESS, NOT BECAUSE
 | THE FEATURE IS MISSING. The distinction matters, so here is the evidence.
 |
 | `$request->session()->regenerate()` IS called at the password boundary (see
 | StaffSessionController::establishSession). But when that line was commented
 | out, this test STAYED GREEN — so it never observed the property it names.
 |
 | The reason is the same harness limitation that defeated the hint tests: the
 | HTTP test client does not replay Set-Cookie, so each simulated request starts
 | its own session. The pre-login and post-login ids differ because they are two
 | unrelated sessions, not because anything regenerated. A test that cannot tell
 | those apart cannot prove fixation resistance.
 |
 | SEC-04 boundary 1 is therefore IMPLEMENTED BUT UNPROVEN, and recorded that way
 | in traceability rather than counted. The browser suite is where this becomes
 | provable, because a real browser keeps its cookie jar; that is the next
 | session's work, together with boundary 2 (post-2FA), which does not exist yet.
 | ─────────────────────────────────────────────────────────────────────────────
 */

it('regenerates the session id when the password is accepted (SEC-04, boundary 1)', function () {
    fixationUser();

    [$preLoginId, $postLoginId, $response] = loginAndCaptureSessionId();

    $response->assertOk();

    expect($preLoginId)->not->toBe('')
        ->and($postLoginId)->not->toBe($preLoginId,
            'SEV-1: the session id survived authentication — a planted pre-login id is now an '
            .'authenticated session (SEC-04 fixation)',
        );
})->skip(
    'NOT PROVEN: with regenerate() deliberately removed this test still passed, so it does not observe '
    .'SEC-04 at all — the test client starts a fresh session per request, so the two ids differ either '
    .'way. Skipped rather than left green. Prove it in the browser suite, where cookies persist.'
);

it('does not carry pre-login session data into the authenticated session', function () {
    fixationUser();

    // Regenerating the ID but keeping the payload is the half-fix that looks
    // right: an attacker who can write to the pre-login session still controls
    // state the authenticated session then reads.
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.6'])->get('https://localhost/');
    session(['planted_by_attacker' => 'still here']);
    session()->save();

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.6'])
        ->postJson('https://localhost/login', [
            'email' => 'agent@fixture.example',
            'password' => 'the-correct-password',
        ])->assertOk();

    expect(session()->has('planted_by_attacker'))->toBeFalse(
        'pre-login session data survived authentication',
    );
})->skip('regenerate() intentionally PRESERVES session data (it is not invalidate()); Doc 02 does not require dropping it at the password boundary, and dropping it would lose the intended-URL the login flow depends on. Recorded rather than silently asserted either way.');

it('regenerates again after the 2FA challenge (SEC-04, boundary 2)', function () {
    // ─────────────────────────────────────────────────────────────────────────
    // BUILT IN S11 AND PROVEN — IN THE BROWSER SUITE, NOT HERE.
    //
    // The boundary exists now: TwoFactorChallengeController completes through
    // SessionEstablisher, whose regenerate() is the second privilege boundary.
    // But proving it needs the SAME observable S8 established for boundary 1 —
    // the pre-boundary session id GONE FROM STORAGE — and that needs a session
    // that survives two requests. This client does not replay cookies (the S6
    // blocker), so the pending-challenge session cannot reach the challenge
    // request at all.
    //
    // tests/Browser/session.spec.ts ("the full 2FA journey") captures the
    // pending-challenge id, posts the TOTP, and asserts via auth:session-inspect
    // that the old key is gone and the id changed. Kept here as a SIGNPOST so
    // the gap is not re-opened by someone reading only this file, and skipped
    // rather than faked with an assertion this harness cannot make.
    // ─────────────────────────────────────────────────────────────────────────
    expect(true)->toBeTrue();
})->skip('PROVEN in tests/Browser/session.spec.ts (real cookie jar + storage-level assertion); unassertable in this client, which does not replay Set-Cookie. See the comment above.');

/*
|--------------------------------------------------------------------------
| The tenant-hint cookie (owner ruling Q7)
|--------------------------------------------------------------------------
*/

it('writes a signed tenant hint at login that matches the account tenant', function () {
    [$tenant] = fixationUser();

    [, , $response] = loginAndCaptureSessionId();
    $response->assertOk();

    $hint = app(TenantHint::class);

    expect($hint->current())->toBe($tenant->id,
        'the hint cookie does not name the tenant the session actually belongs to',
    );
});

/*
|--------------------------------------------------------------------------
| Q7's authority check, with a POSITIVE CONTROL first
|--------------------------------------------------------------------------
|
| These use actingAs() — the repo's established pattern — rather than carrying
| cookies between requests, and the reason is worth recording. Laravel's test
| client does not replay Set-Cookie the way a browser does, so a "log in, then
| make a second request" test returned 404 for EVERY case: correct hint,
| mismatched hint, forged hint. All three "passed" the tamper assertions while
| proving nothing, which is the same vacuous-green trap the AC-01 suite fell into
| in S4 (see LoginRefused's docblock).
|
| The control below is what makes the rest meaningful: a MATCHING hint must
| reach the endpoint. If that ever starts failing, the negative cases below stop
| being evidence and this file says so out loud.
|
*/

/*
 | ─────────────────────────────────────────────────────────────────────────────
 | STATUS OF THE THREE TESTS BELOW, STATED PLAINLY (S6).
 |
 | The positive control passes. The two negative tests DO NOT, and they are
 | skipped rather than removed or "fixed" by loosening what they assert.
 |
 | The diagnosis, established by probing rather than guessed: the hint cookie set
 | via withUnencryptedCookie() is not visible to `$request->cookie()` on these
 | routes, so `TenantHint::current()` returns null, so VerifyTenantHint finds no
 | hint to disagree with and discards nothing. Every case — matching, mismatched,
 | forged — therefore takes the same path, which is why the negatives "passed"
 | before the positive control existed and why the positive control "passes" now.
 | Neither result is evidence about the defence.
 |
 | What IS proven today: the signature mechanism (unit test below, exhaustive over
 | forgery shapes), that login writes a hint naming the session's own tenant, and
 | that the middleware does not break the authenticated happy path.
 |
 | What is NOT proven: that a PRESENT-and-WRONG hint is refused over HTTP. The
 | mechanism is written and reviewed; it has never been observed rejecting
 | anything. Recorded as a gap in traceability and BUILD-STATUS. The next session
 | should prove it through the browser suite, where cookies behave like cookies,
 | rather than fight the HTTP test client's cookie plumbing.
 | ─────────────────────────────────────────────────────────────────────────────
 */

it('POSITIVE CONTROL: an authenticated request with a MATCHING hint is served', function () {
    [$tenant, $user] = fixationUser();

    $response = test()
        ->actingAs($user, 'staff')
        ->withUnencryptedCookie(TenantHint::COOKIE, app(TenantHint::class)->sign($tenant->id))
        ->getJson('https://localhost/api/staff/settings');

    $response->assertOk();
})->group('tenant-hint');

it('treats a MISMATCHED tenant hint as no session (Q7)', function () {
    [, $user] = fixationUser();

    // A real OTHER workspace, so the hint is valid and validly signed — just not
    // this session's. A nonexistent tenant would fail for uninteresting reasons.
    $other = activateTenant(createTenant());
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $response = test()
        ->actingAs($user, 'staff')
        ->withUnencryptedCookie(TenantHint::COOKIE, app(TenantHint::class)->sign($other->id))
        ->getJson('https://localhost/api/staff/settings');

    expect($response->getStatusCode())->not->toBe(200,
        'SEV-1: a session was served under a tenant hint that does not match its user — one cookie '
        .'edit would point session storage at another tenant\'s keyspace',
    );
})->group('tenant-hint')->skip(
    'NOT PROVEN: the hint cookie is not visible to $request->cookie() under the HTTP test client, so '
    .'this test cannot currently observe VerifyTenantHint rejecting anything — it would pass whether '
    .'the defence worked or not. Skipped rather than left green. Prove it in the browser suite.'
);

it('treats a TAMPERED or ABSENT tenant hint as no session (Q7)', function () {
    [, $user] = fixationUser();

    $forgeries = [
        'unsigned raw uuid' => '019fb000-0000-7000-8000-000000000000',
        'signature mutated' => app(TenantHint::class)->sign('019fb000-0000-7000-8000-000000000000').'x',
        'empty value' => '',
        'separator only' => '|',
    ];

    foreach ($forgeries as $label => $forged) {
        $response = test()
            ->actingAs($user, 'staff')
            ->withUnencryptedCookie(TenantHint::COOKIE, $forged)
            ->getJson('https://localhost/api/staff/settings');

        expect($response->getStatusCode())->not->toBe(200, sprintf(
            'SEV-1: a tenant hint with a %s was accepted', $label,
        ));
    }

    // An ABSENT hint is deliberately NOT rejected — see VerifyTenantHint for
    // why (it is not an attack, and such a session was read from the guest
    // keyspace anyway).
})->group('tenant-hint')->skip(
    'NOT PROVEN, same reason as the mismatched-hint test above: the forged cookie never reaches '
    .'$request->cookie() in this harness, so the assertion cannot distinguish a working defence from '
    .'an absent one. The signature half IS proven by the unit test below.'
);

it('signs and verifies only intact values', function () {
    $hint = app(TenantHint::class);
    $tenantId = '019fb000-0000-7000-8000-000000000000';

    expect($hint->verify($hint->sign($tenantId)))->toBe($tenantId)
        ->and($hint->verify($hint->sign($tenantId).'x'))->toBeNull()
        ->and($hint->verify('019fb000-0000-7000-8000-000000000000|deadbeef'))->toBeNull()
        ->and($hint->verify('no-separator'))->toBeNull()
        ->and($hint->verify(''))->toBeNull();
})->group('tenant-hint');
