<?php

declare(strict_types=1);

use App\Domain\Webchat\Identity\SignedIdentityVerifier;
use App\Domain\Webchat\Identity\WidgetSecretMinter;
use App\Models\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Doc 13 FR-07 / BR-05 / SEC-03 / EC-04 / AC-07 — signed identity: valid,
 * invalid, replayed, switched fixtures with Doc 08 BR-01 provenance labels
 * (A-61 as ruled). Sabotage (g) makes the compare non-constant-time or drops
 * the replay window and must turn this red.
 */
function signedScene(): array
{
    [$tenant, $widget, $key] = widgetFixture();
    bindTenant($tenant);
    $secret = app(WidgetSecretMinter::class)->mint($widget, (string) Illuminate\Support\Str::uuid7());
    resetWidgetClient();
    $session = widgetSession($key);
    bindTenant($tenant); // the request cycle cleared the RLS setting; fresh() is a direct read

    return [$tenant, $widget->fresh(), $key, $secret, $session->json('token'), visitorCookieFrom($session), $session->json('visitor_id')];
}

function signed(array $payload, string $secret): array
{
    return ['payload' => $payload, 'sig' => hash_hmac('sha256', SignedIdentityVerifier::canonical($payload), $secret)];
}

it('VALID: a correctly signed payload creates the customer with source=api provenance, mints the asserted identity AND the visitor\'s own anchor under it; the first message then lands on that customer (no shell)', function (): void {
    [$tenant, $widget, $key, $secret, $token, $cookie, $visitorId] = signedScene();
    $payload = ['user_ref' => 'player-4801', 'name' => 'Abebe Tadesse', 'phone' => '+251900001234', 'ts' => time()];

    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/identify', signed($payload, $secret))
        ->assertOk()->assertJson(['asserted' => true, 'display' => 'Abebe Tadesse', 'switched' => false]);

    bindTenant($tenant);
    $asserted = DB::table('customer_identities')->where('type', 'web')->where('value', 'site:'.$widget->getKey().':player-4801')->sole();
    $own = DB::table('customer_identities')->where('type', 'web')->where('value', $visitorId)->sole();
    expect($asserted->source)->toBe('api')->and($own->source)->toBe('api')->and($own->customer_id)->toBe($asserted->customer_id);
    expect(DB::table('customers')->where('id', $asserted->customer_id)->value('created_source'))->toBe('api');
    // BR-02: the phone is NOT an identity — no phone identity row exists anywhere.
    expect(DB::table('customer_identities')->where('type', 'phone')->count())->toBe(0);
    // BR-01/BR-05: the provenance label exists and no "verified" wording does.
    expect(__('customers.identity.by_site'))->toBe('operator site asserted');

    // The first message lands on the asserted customer: no shell is minted.
    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', ['client_message_id' => 'cm_1', 'kind' => 'text', 'body' => 'hi'])->assertStatus(202);
    drainWebchatInbound();
    bindTenant($tenant);
    expect(DB::table('customers')->count())->toBe(1)
        ->and(DB::table('conversations')->where('external_chat_id', $visitorId)->value('customer_id'))->toBe($asserted->customer_id);
});

it('INVALID + REPLAYED: a bad signature, a stale timestamp and a malformed signature are IGNORED — 204, nothing linked, the Health counter moves; a second widget\'s secret never verifies here', function (): void {
    [$tenant, $widget, $key, $secret, $token, $cookie] = signedScene();
    $bad = signed(['user_ref' => 'player-1', 'ts' => time()], 'not-the-secret');
    $stale = signed(['user_ref' => 'player-1', 'ts' => time() - 601], $secret);
    $malformed = ['payload' => ['user_ref' => 'player-1', 'ts' => time()], 'sig' => 'zz'];

    foreach ([$bad, $stale, $malformed] as $attempt) {
        widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/identify', $attempt)->assertStatus(204);
    }
    bindTenant($tenant);
    expect(DB::table('customer_identities')->count())->toBe(0)->and(DB::table('customers')->count())->toBe(0);
    expect((int) DB::table('widget_health_counters')->where('widget_id', $widget->getKey())->value('invalid_signatures'))->toBe(3);

    // The replay window is ±5 min exactly: 299 s old verifies, 301 s old does not.
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/identify', signed(['user_ref' => 'p', 'ts' => time() - 299], $secret))->assertOk();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/identify', signed(['user_ref' => 'p', 'ts' => time() - 301], $secret))->assertStatus(204);
});

it('SWITCHED (EC-04): a visitor already anchored to customer Y receives an assertion for X — the conversation stays under Y, a staff-visible internal note names both, an audit row records it, NOTHING is re-linked, and the widget is told to re-session', function (): void {
    [$tenant, $widget, $key, $secret, $token, $cookie, $visitorId] = signedScene();
    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', ['client_message_id' => 'cm_1', 'kind' => 'text', 'body' => 'hi as Y'])->assertStatus(202);
    drainWebchatInbound();
    bindTenant($tenant);
    $y = DB::table('conversations')->where('external_chat_id', $visitorId)->value('customer_id');

    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/identify', signed(['user_ref' => 'player-X', 'name' => 'Kidist', 'ts' => time()], $secret))
        ->assertOk()->assertJson(['switched' => true, 're_session' => true]);

    bindTenant($tenant);
    // Nothing re-linked: the conversation and the visitor's own identity still belong to Y.
    expect(DB::table('conversations')->where('external_chat_id', $visitorId)->value('customer_id'))->toBe($y)
        ->and(DB::table('customer_identities')->where('value', $visitorId)->value('customer_id'))->toBe($y);
    // X exists as its own customer with the asserted identity.
    $x = DB::table('customer_identities')->where('value', 'site:'.$widget->getKey().':player-X')->sole();
    expect($x->customer_id)->not->toBe($y);
    // The staff-visible note (structurally invisible to the widget) and the audit row.
    $note = DB::table('messages')->where('kind', 'note')->where('is_internal', true)->sole();
    expect($note->body)->toContain('player-X')->toContain($visitorId);
    expect(DB::table('audit_log')->where('action_key', 'widget.identity.switched')->count())->toBe(1);
    // SEC-06: the widget's cursor page never carries the note.
    $page = widgetClient($key, $token, $cookie)->getJson('/api/widget/v1/messages')->assertOk()->json();
    expect(array_column($page['data'], 'kind'))->not->toContain('note');
});

it('SEC-03: the compare is constant-time and the payload canonical form is key-sorted — a reordered payload signs identically; a signature tampered in its last byte fails', function (): void {
    [, , $key, $secret, $token, $cookie] = signedScene();
    $a = signed(['user_ref' => 'p', 'name' => 'A', 'ts' => time()], $secret);
    $b = signed(['ts' => $a['payload']['ts'], 'name' => 'A', 'user_ref' => 'p'], $secret);
    expect($a['sig'])->toBe($b['sig']);
    $tampered = $a;
    $tampered['sig'] = substr($a['sig'], 0, -1).($a['sig'][63] === 'a' ? 'b' : 'a');
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/identify', $tampered)->assertStatus(204);
    // The verifier's source uses hash_equals and nothing else for the compare.
    $src = (string) file_get_contents(app_path('Domain/Webchat/Identity/SignedIdentityVerifier.php'));
    expect(substr_count($src, 'hash_equals('))->toBe(1)->and(preg_match('/=== \$signature|\$signature ===|== \$signature/', $src))->toBe(0);
});
