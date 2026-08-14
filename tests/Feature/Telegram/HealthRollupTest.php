<?php

declare(strict_types=1);

use App\Domain\Telegram\Health\HealthRollup;
use App\Domain\Telegram\Health\SilentWebhookVerdict;
use App\Models\TelegramBot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Adapters\ScriptedTelegramApi;

/*
|--------------------------------------------------------------------------
| FR-29 / FS-02 / A-22 — the health rollup, its poll, and transition-only
|--------------------------------------------------------------------------
|
| A-22 is strict: channel.health.changed fires on TRANSITIONS only, never
| heartbeats. The apply-twice test is therefore the load-bearing one here.
*/

/** @return array{bot: TelegramBot, tenant: string} */
function healthBot(): array
{
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    $bot = TelegramBot::query()->create([
        'telegram_bot_id' => random_int(1_000_000, 9_999_999),
        'username' => 'fixture_health_'.Str::random(6),
        'display_name' => 'Health Fixture',
        'mode' => 'business',
        'bot_token' => '8144038600:FAKECREDhealthfixturehealthfixture00',
    ]);

    return ['bot' => $bot, 'tenant' => $tenant->identifier()];
}

/** Seed N outbound messages, $failed of them failed, for the bot's rate. */
function healthMessages(string $tenant, string $botId, int $total, int $failed): void
{
    $customer = App\Models\Customer::query()->create([
        'display_name' => 'Health Player', 'username' => '@fixture_health_'.Str::random(6), 'is_shell' => true,
        'created_source' => 'staff',
    ]);
    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId, 'tenant_id' => $tenant, 'channel' => 'telegram',
        'channel_ref_id' => $botId, 'external_chat_id' => 'chat-h',
        'customer_id' => $customer->identifier(), 'state' => 'active',
    ]);

    for ($i = 0; $i < $total; $i++) {
        DB::table('messages')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'conversation_id' => $conversationId,
            'direction' => 'out', 'author_type' => 'agent', 'kind' => 'text', 'body' => 'fixture out '.$i,
            'delivery_state' => $i < $failed ? 'failed_final' : 'sent',
            'delivery_error' => $i < $failed ? json_encode(['reason' => 'blocked_by_user']) : null,
        ]);
    }
}

it('FR-29: the rollup reads the registered thresholds — 60% failed is down, 20% degraded, clean is healthy', function (): void {
    $f = healthBot();
    healthMessages($f['tenant'], $f['bot']->id, 10, 6); // 60% ≥ down_failure_pct (50)
    expect(app(HealthRollup::class)->evaluate($f['bot']))->toBe('down');

    $g = healthBot();
    healthMessages($g['tenant'], $g['bot']->id, 10, 2); // 20% ≥ degraded_failure_pct (10)
    expect(app(HealthRollup::class)->evaluate($g['bot']))->toBe('degraded');

    $h = healthBot();
    healthMessages($h['tenant'], $h['bot']->id, 10, 0);
    expect(app(HealthRollup::class)->evaluate($h['bot']))->toBe('healthy');
});

it('A-22 TRANSITION-ONLY: apply(down) emits ONE closed v2 event + audit; applying the same status again emits NOTHING', function (): void {
    $f = healthBot();
    $rollup = app(HealthRollup::class);

    $rollup->apply($f['bot'], 'down');
    $rollup->apply($f['bot'], 'down'); // the heartbeat that must not exist

    $events = DB::table('outbox')->where('name', 'channel.health.changed')->get();
    expect($events)->toHaveCount(1, 'A-22: one transition, one event — a second apply of the SAME status is a heartbeat');

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $events[0]->payload, true);
    expect(array_keys($payload))->toEqualCanonicalizing(
        ['tenant_id', 'channel', 'channel_ref_id', 'status'],
        'closed payload: no missing OR extra keys',
    );
    expect($payload['status'])->toBe('down')
        ->and($payload['channel'])->toBe('telegram')
        ->and($payload['channel_ref_id'])->toBe($f['bot']->id);

    expect(DB::table('audit_log')->where('action_key', 'telegram.health_rollup_changed')->count())->toBe(1);

    // The row carries the status; health_changed_at is DB time (set in-statement).
    $row = DB::table('telegram_bots')->where('id', $f['bot']->id)->first(['health_status', 'health_changed_at']);
    expect($row->health_status)->toBe('down')->and($row->health_changed_at)->not->toBeNull();
});

it('FS-01 convergence: down writes the sweep-pause redis key; recovery writes it back up', function (): void {
    $f = healthBot();
    $rollup = app(HealthRollup::class);
    $key = sprintf('t:%s:channel-health:telegram:%s', $f['tenant'], $f['bot']->id);

    $rollup->apply($f['bot'], 'down');
    expect(app('redis')->connection('volatile')->command('hget', [$key, 'status']))->toBe('down');

    $rollup->apply($f['bot'], 'healthy');
    expect(app('redis')->connection('volatile')->command('hget', [$key, 'status']))->toBe('up');

    // Two transitions, two events — recovery IS a transition (A-22).
    expect(DB::table('outbox')->where('name', 'channel.health.changed')->count())->toBe(2);
});

it('FS-02 POSITIVE CONTROL: pending growth + silence past threshold on a normally-active bot = broken; inbound continuing = not broken', function (): void {
    // broken: grew, active history, silent past threshold
    $broken = SilentWebhookVerdict::evaluate(previousPending: 3, currentPending: 912, normallyActive: true, silencePastThreshold: true);
    expect($broken->broken)->toBeTrue();

    // control 1: pending grew but inbound continued — suspicious, not broken
    $flowing = SilentWebhookVerdict::evaluate(previousPending: 3, currentPending: 40, normallyActive: true, silencePastThreshold: false);
    expect($flowing->broken)->toBeFalse()->and($flowing->suspicious)->toBeTrue();

    // control 2: a NEVER-active bot does not alarm (F12 owns that case)
    $newBot = SilentWebhookVerdict::evaluate(previousPending: 0, currentPending: 15, normallyActive: false, silencePastThreshold: true);
    expect($newBot->broken)->toBeFalse();

    // control 3: pending flat — nothing
    $flat = SilentWebhookVerdict::evaluate(previousPending: 12, currentPending: 12, normallyActive: true, silencePastThreshold: true);
    expect($flat->broken)->toBeFalse()->and($flat->suspicious)->toBeFalse();
});

it('FS-02 through the POLL: getWebhookInfo growth on a silent normally-active bot drives the rollup to down and snapshots the row', function (): void {
    $f = healthBot();
    // Normally active: inbound 1h ago (within 7 days), silent past the 60-min threshold.
    DB::update("UPDATE telegram_bots SET last_inbound_at = clock_timestamp() - interval '2 hours', webhook_pending_updates = 3 WHERE id = ?", [$f['bot']->id]);

    $api = new ScriptedTelegramApi;
    $api->script(['pending_update_count' => 912, 'last_error_message' => null]);
    app()->instance(App\Domain\Telegram\Api\TelegramApi::class, $api);

    $this->artisan('telegram:health-poll')->assertSuccessful();

    $row = DB::table('telegram_bots')->where('id', $f['bot']->id)
        ->first(['health_status', 'webhook_pending_updates', 'last_polled_at']);
    expect($row->health_status)->toBe('down', 'FS-02: silent-broken drives the rollup down')
        ->and((int) $row->webhook_pending_updates)->toBe(912)
        ->and($row->last_polled_at)->not->toBeNull();

    expect(DB::table('outbox')->where('name', 'channel.health.changed')->count())->toBe(1);
});

it('NFR (FR-29 rollup ≤1 s): evaluate + panel metrics + apply complete inside the budget (dev-hardware-indicative)', function (): void {
    $f = healthBot();
    healthMessages($f['tenant'], $f['bot']->id, 50, 5);

    $rollup = app(HealthRollup::class);
    $start = hrtime(true);
    $status = $rollup->evaluate($f['bot']);
    $rollup->panelMetrics($f['bot']->id);
    $rollup->apply($f['bot'], $status);
    $ms = (hrtime(true) - $start) / 1e6;

    fwrite(STDERR, sprintf("[dev-hardware-indicative] FR-29 rollup pass: %.1f ms (budget 1000 ms) %s\n", $ms, $ms <= 1000 ? 'WITHIN' : 'OVER'));
    expect($ms)->toBeLessThan(1000.0);
});

it('FR-22 wiring: the telegram:window-sweep command sweeps a due window across tenant scope (the step-8 gap, closed)', function (): void {
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    $customer = App\Models\Customer::query()->create([
        'display_name' => 'Sweep CLI Player', 'username' => '@fixture_cli_'.Str::random(6), 'is_shell' => true,
        'created_source' => 'staff',
    ]);
    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId, 'tenant_id' => $tenant->identifier(), 'channel' => 'telegram',
        'channel_ref_id' => 'bot-cli', 'external_chat_id' => 'chat-cli',
        'customer_id' => $customer->identifier(), 'state' => 'active',
    ]);
    DB::statement("UPDATE conversations SET reply_window_expires_at = clock_timestamp() - interval '1 minute', reply_window_state = 'open' WHERE id = ?", [$conversationId]);
    $expiry = DB::table('conversations')->where('id', $conversationId)->value('reply_window_expires_at');
    app(App\Domain\Telegram\Window\WindowTracker::class)->index($conversationId, (string) $expiry);

    $this->artisan('telegram:window-sweep')->assertSuccessful();

    expect(DB::table('conversations')->where('id', $conversationId)->value('reply_window_state'))->toBe('expired');
});
