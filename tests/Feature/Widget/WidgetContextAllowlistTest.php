<?php

declare(strict_types=1);

use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Doc 13 FR-24 — page context: undeclared capture() keys are DROPPED
 * server-side before the row is touched (the structural clause; sabotage (k)
 * persists an undeclared key and must turn this red); one row per
 * conversation, latest wins, `captured_at` from the DB clock; nothing stored
 * before the first message (no conversation, no row).
 */
it('FR-24: only allowlisted custom keys survive; the row is one per conversation and the latest capture wins; no conversation → nothing stored', function (): void {
    [$tenant, $widget, $key] = widgetFixture();
    resetWidgetClient();
    $session = widgetSession($key);
    $token = $session->json('token');
    $cookie = visitorCookieFrom($session);

    // Before any message: 204 and NO row.
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/context', ['url' => 'https://customer.harness.localhost:8443/'])->assertStatus(204);
    bindTenant($tenant);
    expect(DB::table('widget_visitor_contexts')->count())->toBe(0);

    // Declare an allowlist on a live config version, then send the first message.
    $versionId = (string) Illuminate\Support\Str::uuid7();
    DB::table('widget_config_versions')->insert([
        'id' => $versionId, 'tenant_id' => $tenant->identifier(), 'widget_id' => $widget->getKey(), 'version' => 1,
        'config' => json_encode(['behavior' => ['context_allowlist' => ['plan', 'deposit_method']]]), 'published_by' => (string) Illuminate\Support\Str::uuid7(),
    ]);
    DB::table('widgets')->where('id', $widget->getKey())->update(['live_version_id' => $versionId]);
    Queue::fake();
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/messages', ['client_message_id' => 'cm_1', 'kind' => 'text', 'body' => 'hi'])->assertStatus(202);
    drainWebchatInbound();

    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/context', [
        'url' => 'https://customer.harness.localhost:8443/deposit', 'title' => 'Deposit', 'viewport' => ['w' => 375, 'h' => 812], 'language' => 'am', 'tz_offset_minutes' => 180,
        'custom' => ['plan' => 'vip', 'deposit_method' => 'telebirr', 'session_cookie' => 'STOLEN', 'password' => 'hunter2', 'nested' => ['a' => 1]],
    ])->assertStatus(204);

    bindTenant($tenant);
    $row = DB::table('widget_visitor_contexts')->sole();
    $custom = json_decode((string) $row->custom, true);
    expect($custom)->toBe(['plan' => 'vip', 'deposit_method' => 'telebirr'], 'an undeclared key was persisted (FR-24)')
        ->and($row->url)->toBe('https://customer.harness.localhost:8443/deposit')->and($row->viewport_w)->toBe(375)->and($row->tz_offset_minutes)->toBe(180);
    // Nothing undeclared reaches the row in ANY column.
    expect(json_encode($row))->not->toContain('STOLEN')->not->toContain('hunter2');

    // Latest wins: a second capture updates the SAME row and moves captured_at (the trigger).
    $first = $row->captured_at;
    DB::statement('SELECT pg_sleep(0.01)');
    widgetClient($key, $token, $cookie)->postJson('/api/widget/v1/context', ['url' => 'https://customer.harness.localhost:8443/withdraw'])->assertStatus(204);
    bindTenant($tenant);
    $again = DB::table('widget_visitor_contexts')->sole();
    expect($again->url)->toBe('https://customer.harness.localhost:8443/withdraw')->and($again->captured_at)->not->toBe($first);
    expect(Conversation::query()->count())->toBe(1);
});
