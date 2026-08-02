<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

/**
 * BR-06 — the guard partition, both directions (Doc 02 §7; step-10 plan).
 *
 * "Visitor tokens grant zero access to staff surfaces STRUCTURALLY (separate
 * guard, route group, cookie scope) regardless of any scope misconfiguration."
 *
 * Structure, not policy, is what this file pins:
 *
 *   - the widget guard's principal is a WidgetVisitor value object — not a
 *     User, not a model, not retrievable by any user provider, so `auth:staff`
 *     and `auth:api` cannot accept it under ANY configuration;
 *   - the widget guard consults only the Redis visitor store, so no staff
 *     credential (session cookie, bearer token) means anything to it;
 *   - config/auth.php gives the widget guard NO provider key to misconfigure;
 *   - the FR-22 cookie is path-scoped to /api/widget, so it does not even
 *     ACCOMPANY staff requests.
 *
 * Every refusal below has a positive control beside it (the same credential
 * succeeding on its OWN surface) — two refusals compared to each other prove
 * nothing, per the standing AC-01 lesson.
 */
it('refuses a valid visitor session on the staff and bearer surfaces (BR-06, visitor → staff direction)', function (): void {
    [, , $key] = widgetFixture();

    $issued = widgetSession($key)->assertCreated();
    $token = $issued->json('token');
    $cookie = visitorCookieFrom($issued);

    // Positive control: this exact session works on its own surface.
    widgetContext($key, $token, $cookie)->assertOk();

    // Staff surface: the visitor token means nothing to session resolution —
    // 404, the same nothing any unauthenticated probe gets (C-10 shape).
    app('auth')->forgetGuards();
    test()->withHeaders(['X-Visitor-Token' => $token])
        ->withUnencryptedCookie('nlw_visitor', $cookie)
        ->withCredentials()
        ->getJson('https://localhost/api/staff/context')
        ->assertNotFound();

    // Bearer surface: the visitor token AS the bearer credential is refused
    // at the door with the closed FR-20 code set.
    app('auth')->forgetGuards();
    test()->getJson('https://localhost/api/v1/token/context', [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'invalid_token');
});

it('refuses staff credentials on the widget surface (BR-06, staff → visitor direction)', function (): void {
    [$tenant, , $key] = widgetFixture();

    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'partition.staff@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // Positive control: the staff session works on the staff surface.
    test()->actingAs($user, 'staff')
        ->getJson('https://localhost/api/staff/context')
        ->assertOk();

    // The SAME authenticated test client hitting the widget probe: the widget
    // guard consults only the visitor store, finds no token header, refuses.
    // The staff principal is invisible to it — not denied, invisible.
    test()->actingAs($user, 'staff')
        ->withHeaders(['Authorization' => 'Bearer '.$key, 'Origin' => WIDGET_ORIGIN])
        ->getJson('https://localhost/api/widget/v1/context')
        ->assertUnauthorized();

    // A staff API token on the widget surface: ResolveTenant reads the
    // Authorization header as the WIDGET KEY and resolves nothing — the
    // credential-confusion replay dies at resolution, before any guard.
    $mint = test()->actingAs($user, 'staff')->postJson('https://localhost/api/staff/security/tokens', [
        'name' => 'partition probe',
        'abilities' => ['settings.view'],
        'expiry' => '90',
    ])->assertCreated();

    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => 'Bearer '.$mint->json('plaintext'), 'Origin' => WIDGET_ORIGIN])
        ->getJson('https://localhost/api/widget/v1/context')
        ->assertNotFound();
});

it('gives the widget guard no user provider to misconfigure (BR-06 structure)', function (): void {
    expect(config('auth.guards.widget'))->toBe(['driver' => 'widget-visitor']);

    // The partition's other half is pinned by the theft suite at runtime:
    // WidgetGuard::setUser() throws on any non-WidgetVisitor principal.
});

/**
 * The suite bootstrap pins BROADCAST_CONNECTION=null so unrelated tests never
 * try to reach a Reverb server — but the NullBroadcaster's auth() approves
 * EVERY channel with an empty 200, which would make these channel tests pass
 * while proving nothing. So the channel tests swap in the REAL reverb driver
 * (Pusher-protocol HMAC, no server contact at auth time) and re-run the
 * channels file so the callbacks register on it — the same driver production
 * boots with.
 */
function useRealBroadcastAuth(): void
{
    config()->set('broadcasting.default', 'reverb');

    // Channel callbacks were registered on the null driver at boot; the
    // freshly-built reverb driver starts empty. Same file, same callbacks,
    // real driver.
    require base_path('routes/channels.php');
}

it('authorizes a visitor onto exactly their own Reverb channel and nothing else (FR-21)', function (): void {
    useRealBroadcastAuth();

    [$tenant, , $key] = widgetFixture();

    $issued = widgetSession($key)->assertCreated();
    $token = $issued->json('token');
    $cookie = visitorCookieFrom($issued);
    $visitorId = $issued->json('visitor_id');

    $broadcastAuth = function (string $channel) use ($key, $token, $cookie) {
        app('auth')->forgetGuards();

        return test()->withHeaders([
            'Authorization' => 'Bearer '.$key,
            'Origin' => WIDGET_ORIGIN,
            'X-Visitor-Token' => $token,
        ])->withUnencryptedCookie('nlw_visitor', $cookie)
            ->withCredentials()
            ->postJson('https://localhost/api/widget/v1/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id' => '1234.5678',
            ]);
    };

    // Positive control: the visitor's own channel authorizes.
    $own = $broadcastAuth(sprintf('private-tenant.%s.visitor.%s', $tenant->id, $visitorId));
    $own->assertOk();
    expect($own->json('auth'))->toBeString()->not->toBe('');

    // Another visitor's channel: refused by the callback's comparison.
    $broadcastAuth(sprintf('private-tenant.%s.visitor.%s', $tenant->id, (string) Illuminate\Support\Str::uuid7()))
        ->assertForbidden();

    // The STAFF tenant channel: that channel authenticates via the staff
    // guard (its Broadcast::channel declares no widget guard), so a visitor
    // presenting there retrieves no principal at all.
    $broadcastAuth(sprintf('private-tenant.%s', $tenant->id))
        ->assertForbidden();

    // A different tenant's visitor channel, same visitor id: the name says
    // another tenant, the principal says this one — refused on comparison.
    $broadcastAuth(sprintf('private-tenant.%s.visitor.%s', (string) Illuminate\Support\Str::uuid7(), $visitorId))
        ->assertForbidden();
});

it('refuses the widget broadcasting door to anyone who is not a visitor (BR-06 at the socket layer)', function (): void {
    useRealBroadcastAuth();

    [$tenant, , $key] = widgetFixture();

    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // A staff session presenting at the WIDGET broadcast door: auth:widget
    // finds no visitor token and refuses before any channel callback runs.
    test()->actingAs($user, 'staff')
        ->withHeaders(['Authorization' => 'Bearer '.$key, 'Origin' => WIDGET_ORIGIN])
        ->postJson('https://localhost/api/widget/v1/broadcasting/auth', [
            'channel_name' => sprintf('private-tenant.%s', $tenant->id),
            'socket_id' => '1234.5678',
        ])
        ->assertUnauthorized();
});
