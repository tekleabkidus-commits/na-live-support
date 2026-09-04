<?php

declare(strict_types=1);

use App\Models\Customer;

/**
 * Doc 13 FR-05 — "the Doc 08 resolver creates the shell customer on first
 * MESSAGE (not first load — no ghost profiles from bounces)". A COUNT
 * assertion in both directions (plan §10.4; sabotage (f) teaches the session
 * door to mint a customer and must turn this red).
 */
it('FR-05: a session + a config read + a cursor read mint NO customer; the first message mints exactly one (A-61: mint on the first message only)', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    resetWidgetClient();

    // Counts read the BOUND default connection: the test runs inside a rolled-back transaction
    // that only this connection can see (a platform-connection read would count committed rows only).
    $count = static function () use ($tenant): int {
        bindTenant($tenant);

        return Customer::query()->count();
    };
    $before = $count();

    $session = widgetSession($key);
    $token = $session->json('token');
    $cookie = visitorCookieFrom($session);

    widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/config')->assertOk();
    widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/messages')->assertOk();

    $afterLoad = $count();
    expect($afterLoad)->toBe($before, 'a bounce left a ghost customer behind (FR-05)');

    Illuminate\Support\Facades\Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', [
        'client_message_id' => 'cm_'.bin2hex(random_bytes(6)),
        'kind' => 'text',
        'body' => 'Selam — my deposit has not arrived',
    ])->assertStatus(202);
    drainWebchatInbound();

    $afterMessage = $count();
    expect($afterMessage)->toBe($before + 1, 'the first message must mint exactly one shell customer');

    bindTenant($tenant);
    $customer = Customer::query()->latest('created_at')->firstOrFail();
    expect($customer->is_shell)->toBeTrue()->and($customer->created_source)->toBe('webchat');
});
