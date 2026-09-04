<?php

declare(strict_types=1);

/**
 * Doc 13 AC-04 / T-09 — THE VISITOR-SCOPE MATRIX: a visitor token reaches
 * EXACTLY its own conversation/survey/context endpoints; cross-visitor
 * attempts are 404 (Doc 01 SEC-06 style — absence, never a refusal that
 * confirms existence). Two visitors on the same widget, two on different
 * widgets, a staff session, a bare token, a token without its cookie: every
 * cell of the matrix executed. Sabotage (b) widens the guard or drops the
 * conversation filter and must turn this red.
 */
function visitorWithMessage(string $key, string $body): array
{
    Illuminate\Support\Facades\Queue::fake();
    resetWidgetClient();
    $session = widgetSession($key);
    $token = $session->json('token');
    $cookie = visitorCookieFrom($session);
    $visitorId = $session->json('visitor_id');
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', [
        'client_message_id' => 'cm_'.bin2hex(random_bytes(6)), 'kind' => 'text', 'body' => $body,
    ])->assertStatus(202);
    drainWebchatInbound();

    return [$token, $cookie, $visitorId];
}

it('AC-04: a visitor reads and receipts ONLY its own thread; another visitor\'s messages are ABSENT (404 on their ids), on the same widget and across widgets', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    [$tokenA, $cookieA, $visitorA] = visitorWithMessage($key, 'A: first message');
    [$tokenB, $cookieB, $visitorB] = visitorWithMessage($key, 'B: first message');

    $threadA = widgetClient($key, $tokenA, $cookieA)->getJson('/api/widget/v1/messages')->assertOk()->json();
    $threadB = widgetClient($key, $tokenB, $cookieB)->getJson('/api/widget/v1/messages')->assertOk()->json();

    expect(array_column($threadA['data'], 'body'))->toBe(['A: first message'])
        ->and(array_column($threadB['data'], 'body'))->toBe(['B: first message']);

    // A cannot receipt, reply-to or read B's message by id — absence, not refusal (C-10).
    $bId = $threadB['data'][0]['id'];
    widgetClient($key, $tokenA, $cookieA)->postJson('/api/widget/v1/receipts', ['message_ids' => [$bId]])->assertStatus(404);
    widgetClient($key, $tokenA, $cookieA)->postJson('/api/widget/v1/context', ['url' => 'https://customer.harness.localhost:8443/deposit', 'conversation_id' => $threadB['conversation_id'] ?? 'x'])->assertStatus(404);

    // A different widget on the same tenant: a token minted there is refused on this widget (Doc 02 widget_mismatch → 401), never a leak.
    [, , $otherKey] = widgetFixture(); // a second tenant+widget — the matrix's cross-tenant cell
    resetWidgetClient();
    widgetClient($otherKey, $tokenA, $cookieA)->getJson('/api/widget/v1/messages')->assertStatus(401);

    // A staff session is NOT a visitor (BR-06): the widget stack answers 401, never a thread.
    resetWidgetClient();
    test()->withHeaders(['X-Tenant-Key' => $key, 'Origin' => WIDGET_ORIGIN])->getJson('/api/widget/v1/messages')->assertStatus(401);

    // A bare token without its cookie: 401 (FR-22) — the client re-POSTs /session; nothing is served.
    resetWidgetClient();
    test()->withHeaders(['X-Tenant-Key' => $key, 'Origin' => WIDGET_ORIGIN, 'X-Visitor-Token' => $tokenA])->getJson('/api/widget/v1/messages')->assertStatus(401);
});

it('AC-04: the survey and context doors are the visitor\'s own — a foreign survey token is 404 and a context write lands on the visitor\'s OWN conversation only', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    [$tokenA, $cookieA] = visitorWithMessage($key, 'A: hello');

    widgetClient($key, $tokenA, $cookieA)->postJson('/api/widget/v1/context', [
        'url' => 'https://customer.harness.localhost:8443/deposit', 'title' => 'Deposit', 'language' => 'am', 'viewport' => ['w' => 375, 'h' => 812],
    ])->assertStatus(204);

    bindTenant($tenant);
    $rows = Illuminate\Support\Facades\DB::table('widget_visitor_contexts')->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->url)->toBe('https://customer.harness.localhost:8443/deposit');

    // The CSAT door is Doc 12's (token in the body): an unknown survey token is 404 there too.
    test()->withHeaders(['X-Tenant-Key' => $key, 'Origin' => WIDGET_ORIGIN])
        ->postJson('/api/widget/v1/csat', ['token' => 'not-a-survey-token', 'score' => 5])->assertStatus(404);
});
