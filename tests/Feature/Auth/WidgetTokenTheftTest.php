<?php

declare(strict_types=1);

use App\Domain\Auth\Widget\VisitorTokenStore;
use App\Domain\Auth\Widget\WidgetVisitor;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * FR-22 theft containment + EC-09 (Doc 02 §4.6; step-10 rulings 2 and 3).
 *
 * THE APPROVED INTERPRETATION, stated here because the FR's sentence is dense
 * (owner ruling, step 10, decision 3):
 *
 *   FR-22's "history preserved server-side via visitor_id" protects the
 *   RECORD, not the PRESENTER. A token presented without its paired cookie is
 *   never trusted as its visitor: on /session it silently receives a FRESH
 *   visitor_id (the thief gets an empty identity, not the victim's), and on
 *   any other widget endpoint it gets a plain 401 whose client-side remedy is
 *   re-POSTing /session. The victim's record — visitor_id, cookie pairing,
 *   TTL — is untouched throughout, and every mismatch logs a security metric.
 *
 *   Re-linking a fresh visitor to an old record is EXCLUSIVELY a staff action
 *   (EC-09; Doc 08 owns the merge). Accordingly this suite asserts not only
 *   the behavior but the ABSENCE OF THE CAPABILITY: no VisitorTokenStore
 *   operation accepts a caller-chosen visitor identity, so the linking code
 *   path does not exist to be misused.
 *
 * Rotation cadence (decision 2): the cookie rotates on every /session call,
 * and exactly one previous generation stays valid — parallel tabs and
 * in-flight requests survive a rotation; anything older is dead.
 */
it('rotates the cookie on every /session refresh, keeping visitor and token stable (FR-22, decision 2)', function (): void {
    [, , $key] = widgetFixture();

    $first = widgetSession($key)->assertCreated();
    $token = $first->json('token');
    $visitorId = $first->json('visitor_id');
    $cookie1 = visitorCookieFrom($first);

    // Positive control: the pair authenticates before anything rotates.
    widgetContext($key, $token, $cookie1)->assertOk();

    // The rightful holder returns: 200 (not 201), SAME visitor, SAME token,
    // DIFFERENT cookie.
    $refresh = widgetSession($key, headers: ['X-Visitor-Token' => $token], cookie: $cookie1);
    $refresh->assertOk();
    expect($refresh->json('visitor_id'))->toBe($visitorId);
    expect($refresh->json('token'))->toBe($token);

    $cookie2 = visitorCookieFrom($refresh);
    expect($cookie2)->not->toBe($cookie1);

    // Grace: current and immediately-previous cookies both authenticate…
    widgetContext($key, $token, $cookie2)->assertOk();
    widgetContext($key, $token, $cookie1)->assertOk();

    // …but two generations back is dead. Rotate once more, then present the
    // original: refused.
    $third = widgetSession($key, headers: ['X-Visitor-Token' => $token], cookie: $cookie2);
    $third->assertOk();
    widgetContext($key, $token, $cookie1)->assertUnauthorized();
});

it('never lets a bare token act as its visitor — API requests get 401, nothing else (FR-22, decision 3)', function (): void {
    [, , $key] = widgetFixture();

    $issued = widgetSession($key)->assertCreated();
    $token = $issued->json('token');
    $cookie = visitorCookieFrom($issued);

    // Positive control first.
    widgetContext($key, $token, $cookie)->assertOk();

    // The thief has the token and not the cookie: no cookie, wrong cookie —
    // both plain 401s, no re-issue, no hint which half failed.
    widgetContext($key, $token, null)->assertUnauthorized();
    widgetContext($key, $token, bin2hex(random_bytes(16)))->assertUnauthorized();

    // And the victim is entirely unaffected.
    widgetContext($key, $token, $cookie)->assertOk();
});

it('silently issues a FRESH identity to a token without its cookie on /session, leaving the victim untouched (FR-22)', function (): void {
    [$tenant, , $key] = widgetFixture();

    $victim = widgetSession($key)->assertCreated();
    $victimToken = $victim->json('token');
    $victimVisitorId = $victim->json('visitor_id');
    $victimCookie = visitorCookieFrom($victim);

    $store = app(VisitorTokenStore::class);
    $before = $store->find($tenant->id, $victimToken);

    // The thief presents the stolen token with no cookie: SILENT — a normal
    // 201, a working session — but a brand-new visitor with no history.
    $thief = widgetSession($key, headers: ['X-Visitor-Token' => $victimToken]);
    $thief->assertCreated();
    expect($thief->json('visitor_id'))->not->toBe($victimVisitorId);
    expect($thief->json('token'))->not->toBe($victimToken);

    // The victim's record: same visitor_id, same cookie pairing — the theft
    // attempt did not rotate, revoke, or even touch it.
    $after = $store->find($tenant->id, $victimToken);
    expect($after->visitorId)->toBe($before->visitorId);
    expect($after->cookieHash)->toBe($before->cookieHash);
    expect($after->previousCookieHash)->toBe($before->previousCookieHash);

    // And the victim's pair still works.
    widgetContext($key, $victimToken, $victimCookie)->assertOk();
});

it('gives a visitor who cleared cookies a fresh identity with no path back to the old one (EC-09)', function (): void {
    [$tenant, , $key] = widgetFixture();

    $old = widgetSession($key)->assertCreated();
    $oldToken = $old->json('token');
    $oldVisitorId = $old->json('visitor_id');

    // Cleared cookies = the next load presents nothing at all.
    $fresh = widgetSession($key)->assertCreated();
    expect($fresh->json('visitor_id'))->not->toBe($oldVisitorId);
    expect($fresh->json('token'))->not->toBe($oldToken);

    // The old record survives server-side, unlinked — staff can merge later
    // (Doc 08); nothing here did.
    $oldRecord = app(VisitorTokenStore::class)->find($tenant->id, $oldToken);
    expect($oldRecord->visitorId)->toBe($oldVisitorId);
});

it('offers NO store operation that accepts a caller-chosen visitor identity (EC-09 — the capability is absent)', function (): void {
    // The ruling asks for structure, not just behavior: linking a fresh
    // session to an existing visitor would require an operation that takes a
    // visitor id (or another record) and writes a session for it. There is
    // none: issue() manufactures its own identity internally, and every other
    // public operation only re-writes the record a valid token already names.
    $reflection = new ReflectionClass(VisitorTokenStore::class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        foreach ($method->getParameters() as $parameter) {
            expect(strtolower($parameter->getName()))->not->toContain('visitor');
        }
    }

    // And the record object cannot be minted around the store into a linkage:
    // rotateCookie/touch preserve the record's own visitorId by construction —
    // proven behaviorally above; here we pin that no public store method
    // returns a WRITE HANDLE for an arbitrary visitor id.
    expect($reflection->hasMethod('linkVisitor'))->toBeFalse();
    expect($reflection->hasMethod('adopt'))->toBeFalse();
});

it('refuses a token minted under one widget when presented under a sibling widget\'s key (FR-21 binding)', function (): void {
    [$tenant, , $keyA] = widgetFixture();

    // A second widget in the SAME tenant, same allowlist.
    $keyB = asTenant($tenant, function (): string {
        $issued = app(App\Support\Tenancy\TenantKeyIssuer::class)->issue(
            kind: 'widget',
            label: 'second fixture widget',
            actor: App\Support\Audit\AuditActor::PlatformAdmin,
        );
        App\Models\Widget::query()->create([
            'key_id' => $issued['key']->getKey(),
            'allowed_origins' => [WIDGET_ORIGIN],
        ]);

        return $issued['plaintext'];
    });

    app(App\Support\Tenancy\TenantContext::class)->clear();
    app(App\Support\Tenancy\RlsSession::class)->clear();

    $issued = widgetSession($keyA)->assertCreated();
    $token = $issued->json('token');
    $cookie = visitorCookieFrom($issued);

    // Positive control under widget A…
    widgetContext($keyA, $token, $cookie)->assertOk();

    // …and refusal under widget B: same tenant, wrong widget. The API path
    // 401s; /session under B silently issues a FRESH visitor for B.
    widgetContext($keyB, $token, $cookie)->assertUnauthorized();

    $reissued = widgetSession($keyB, headers: ['X-Visitor-Token' => $token], cookie: $cookie);
    $reissued->assertCreated();
    expect($reissued->json('visitor_id'))->not->toBe($issued->json('visitor_id'));
});

it('refuses to plant a staff User behind the widget guard even deliberately (BR-06, sabotage f\'s runtime twin)', function (): void {
    [$tenant] = widgetFixture();

    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));

    app(App\Support\Tenancy\TenantContext::class)->clear();
    app(App\Support\Tenancy\RlsSession::class)->clear();

    app('auth')->forgetGuards();

    expect(fn () => auth()->guard('widget')->setUser($user))
        ->toThrow(LogicException::class, 'BR-06');
});

it('carries a WidgetVisitor principal that fails loudly if treated as a credentialed account (BR-06)', function (): void {
    $visitor = new WidgetVisitor(visitorId: 'v', tenantId: 't', widgetId: 'w');

    expect(fn () => $visitor->getAuthPassword())->toThrow(LogicException::class);
    expect(fn () => $visitor->getRememberToken())->toThrow(LogicException::class);
    expect($visitor)->not->toBeInstanceOf(User::class);
    expect($visitor)->not->toBeInstanceOf(Illuminate\Database\Eloquent\Model::class);
});
