<?php

declare(strict_types=1);

use App\Domain\Telegram\Window\WindowIndexRebuilder;
use App\Domain\Telegram\Window\WindowSweep;
use App\Domain\Telegram\Window\WindowTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| FR-22 / FS-01 / FS-05 — the sweep, its outage pause, and index rebuild
|--------------------------------------------------------------------------
|
| FS-01's outage-pause test comes BEFORE the sweep's transitions are trusted
| (protocol order): the sweep must be proven never to emit a false "expired"
| during a verified outage before we rely on its expiries at all.
*/

/** @return array{conversation: string, tenant: string, bot: string} */
function sweepConversation(string $state, string $expiresSql): array
{
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    $customer = App\Models\Customer::query()->create([
        'display_name' => 'Sweep Player', 'username' => '@fixture_sweep_'.Str::random(6), 'is_shell' => true,
        'created_source' => 'staff',
    ]);
    $conversationId = (string) Str::uuid7();
    $bot = 'bot-sweep';
    DB::table('conversations')->insert([
        'id' => $conversationId, 'tenant_id' => $tenant->identifier(), 'channel' => 'telegram',
        'channel_ref_id' => $bot, 'business_connection_id' => 'conn-s',
        'external_chat_id' => 'chat-s', 'customer_id' => $customer->identifier(), 'state' => 'active',
    ]);
    DB::statement("UPDATE conversations SET reply_window_expires_at = {$expiresSql}, reply_window_state = ? WHERE id = ?", [$state, $conversationId]);

    $tracker = app(WindowTracker::class);
    $expiry = DB::table('conversations')->where('id', $conversationId)->value('reply_window_expires_at');
    $tracker->index($conversationId, (string) $expiry);

    return ['conversation' => $conversationId, 'tenant' => $tenant->identifier(), 'bot' => $bot];
}

function nowMicros(): int
{
    return (int) DB::selectOne('SELECT (extract(epoch from clock_timestamp()) * 1000000)::bigint AS m')->m;
}

function setHealthDown(string $tenant, string $bot): void
{
    app('redis')->connection('volatile')->command('hset', [
        sprintf('t:%s:channel-health:telegram:%s', $tenant, $bot), 'status', 'down',
    ]);
}

it('FS-01 (BEFORE trust): the sweep does NOT expire a window while the channel is health=down — no false expired', function (): void {
    // A window already past expiry, but the bot is DOWN.
    $c = sweepConversation('open', "clock_timestamp() - interval '1 minute'");
    setHealthDown($c['tenant'], $c['bot']);

    $counts = app(WindowSweep::class)->run(nowMicros());

    expect($counts['paused'])->toBe(1)->and($counts['expired'])->toBe(0);
    expect(DB::table('conversations')->where('id', $c['conversation'])->value('reply_window_state'))
        ->toBe('open', 'FS-01: no false expired under outage — state unchanged');
    expect(DB::table('outbox')->where('name', 'telegram.window.expired')->count())->toBe(0);
});

it('FS-01: when health RECOVERS, the next sweep expires it (recompute resumes)', function (): void {
    $c = sweepConversation('open', "clock_timestamp() - interval '1 minute'");
    setHealthDown($c['tenant'], $c['bot']);
    app(WindowSweep::class)->run(nowMicros());

    // Health recovers (up).
    app('redis')->connection('volatile')->command('hset', [
        sprintf('t:%s:channel-health:telegram:%s', $c['tenant'], $c['bot']), 'status', 'up',
    ]);

    $counts = app(WindowSweep::class)->run(nowMicros());
    expect($counts['expired'])->toBe(1);
    expect(DB::table('conversations')->where('id', $c['conversation'])->value('reply_window_state'))->toBe('expired');
});

it('FR-22: the sweep emits closing (≤2h) and expired (past) on TRANSITIONS only — a second sweep re-emits nothing', function (): void {
    $closing = sweepConversation('open', "clock_timestamp() + interval '1 hour'");   // within the 2h band
    $expired = sweepConversation('open', "clock_timestamp() - interval '1 minute'"); // past
    // both are separate tenants (sweepConversation binds a fresh one); run per-tenant.

    // closing tenant
    bindTenant($closing['tenant']);
    $c1 = app(WindowSweep::class)->run(nowMicros());
    expect($c1['closing'])->toBe(1);
    expect(DB::table('conversations')->where('id', $closing['conversation'])->value('reply_window_state'))->toBe('closing');
    // idempotent: second sweep emits nothing new
    $c1b = app(WindowSweep::class)->run(nowMicros());
    expect($c1b['closing'])->toBe(0);
    expect(DB::table('outbox')->where('name', 'telegram.window.closing')->count())->toBe(1);

    // expired tenant
    bindTenant($expired['tenant']);
    $c2 = app(WindowSweep::class)->run(nowMicros());
    expect($c2['expired'])->toBe(1);
    expect(DB::table('outbox')->where('name', 'telegram.window.expired')->count())->toBe(1);
});

it('FS-05: after the ZSET is lost, the rebuilder repopulates it from the column and the sweep works again', function (): void {
    $c = sweepConversation('open', "clock_timestamp() + interval '1 hour'");
    $tracker = app(WindowTracker::class);

    // The index is lost (eviction/flush).
    app('redis')->connection('volatile')->command('del', [$tracker->zsetKey()]);
    expect(app('redis')->connection('volatile')->command('zcard', [$tracker->zsetKey()]))->toBe(0);

    // The sweep now finds nothing (index empty) — the column is still truth.
    expect(app(WindowSweep::class)->run(nowMicros())['closing'])->toBe(0);

    // Rebuild from the column, then the sweep recovers.
    $rebuilt = app(WindowIndexRebuilder::class)->rebuild();
    expect($rebuilt)->toBe(1);
    expect(app(WindowSweep::class)->run(nowMicros())['closing'])->toBe(1);
});
