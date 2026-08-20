<?php

declare(strict_types=1);

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Quota\AiQuotaExhausted;
use App\Domain\Ai\Quota\AiQuotaGate;
use App\Support\Quota\Quota;
use App\Support\Quota\QuotaMeter;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\Cache;

/**
 * NFR-06/EC-12/T-07 over Doc 01's quota engine (consumed) + the RULED CORE
 * EDIT's proving obligations: headroom() mirrors allow() to the unit; the
 * EC-12 reserve fixture (sabotage #12's target); the countersign's required
 * metering-OUTAGE fixture (unknown headroom = reserve mode, never open-
 * throttle); actual tokens accumulate post-call into the reconciled counter.
 */
function quotaTenant(int $dailyLimit): void
{
    $tenant = createTenant();
    bindTenant($tenant);
    app(SettingsRepository::class)->set(Quota::AiTokensPerDay->settingKey(), $dailyLimit, tenantSurface: false);
    Cache::flush();
}

function quotaDriver(string $name, int $responses = 3): FakeDriver
{
    $driver = new FakeDriver(new FixturesOnlyEgress);
    for ($i = 0; $i < $responses; $i++) {
        $driver->queue(new ProviderResponse('{"ok":true}', 300, 100)); // 400 tokens per call
    }
    app(ProviderRegistry::class)->swap($name, $driver);
    config(['ai.default_routing.fast' => [['provider' => $name, 'model' => 'm']]]);

    return $driver;
}

it('RULED EDIT obligation: headroom() mirrors allow()\'s own counter to the unit', function (): void {
    quotaTenant(10_000);
    $meter = app(QuotaMeter::class);

    expect($meter->headroom(Quota::AiTokensPerDay))->toBe(['used' => 0, 'limit' => 10_000]);

    $meter->allow(Quota::AiTokensPerDay, 1234, App\Support\Quota\QuotaSurface::Staff);
    $meter->allow(Quota::AiTokensPerDay, 66, App\Support\Quota\QuotaSurface::Staff);

    expect($meter->headroom(Quota::AiTokensPerDay)['used'])->toBe(1300);
});

it('T-07: every orchestrator call accumulates the provider\'s ACTUAL tokens into the reconciled counter', function (): void {
    quotaTenant(10_000);
    quotaDriver('fake-quota');

    app(AiOrchestrator::class)->execute('classify_message', [['role' => 'user', 'content' => '[FIXTURE] a']]);
    app(AiOrchestrator::class)->execute('classify_message', [['role' => 'user', 'content' => '[FIXTURE] b']]);

    expect(app(QuotaMeter::class)->headroom(Quota::AiTokensPerDay)['used'])->toBe(800);
});

it('EC-12 (sabotage #12 target): inside the reserve band NEW turns are refused PRE-CALL — the provider never sees them; below the band they flow', function (): void {
    quotaTenant(1_000); // reserve = 5% = 50 → the band starts at 950
    $driver = quotaDriver('fake-reserve', 5);
    $meter = app(QuotaMeter::class);

    $meter->allow(Quota::AiTokensPerDay, 500, App\Support\Quota\QuotaSurface::Staff); // 500 used: flows
    app(AiOrchestrator::class)->execute('classify_message', [['role' => 'user', 'content' => '[FIXTURE] first']]);
    expect(count($driver->seen))->toBe(1);

    // 900 used now; one more turn spends INTO the band (to 1300) and is
    // allowed to COMPLETE — EC-12's "completes the current turn".
    app(AiOrchestrator::class)->execute('classify_message', [['role' => 'user', 'content' => '[FIXTURE] second']]);
    // The meter never counts past the limit (its own contract — a refused
    // allow() compensates), so the reserve spend FILLS the counter to the
    // cap: the gate's state reads exhausted from here; the overflow rides
    // the reserve_spend metric and ai_decisions' exact tokens (AC-10).
    expect(count($driver->seen))->toBe(2)
        ->and($meter->headroom(Quota::AiTokensPerDay)['used'])->toBe(1000, 'reserve spend did not fill the counter to the cap');

    // Past the cap: the NEXT turn is refused BEFORE any call — straight to a human.
    expect(fn () => app(AiOrchestrator::class)->execute('classify_message', [['role' => 'user', 'content' => '[FIXTURE] third']]))
        ->toThrow(AiQuotaExhausted::class);
    expect(count($driver->seen))->toBe(2, 'a call was billed past the cap — the gate ran post-call (sabotage #12)');
});

it('EC-12: the band itself refuses — at 96% used a new turn is refused (the 5% reserve is for turns already admitted)', function (): void {
    quotaTenant(1_000);
    $driver = quotaDriver('fake-band');
    app(QuotaMeter::class)->allow(Quota::AiTokensPerDay, 960, App\Support\Quota\QuotaSurface::Staff);

    expect(app(AiQuotaGate::class)->admit())->toBe('exhausted');
    expect(fn () => app(AiOrchestrator::class)->execute('classify_message', [['role' => 'user', 'content' => '[FIXTURE] x']]))
        ->toThrow(AiQuotaExhausted::class);
    expect($driver->seen)->toBe([]);
});

it('COUNTERSIGN-REQUIRED FIXTURE: a metering OUTAGE lands in RESERVE MODE — unknown headroom refuses new turns, never open-throttle', function (): void {
    quotaTenant(10_000);
    $driver = quotaDriver('fake-outage');

    // A REAL dead connection: the RedisManager captures its config array at
    // boot, so a config() change + purge alone does NOT redirect it (the
    // Doc 01 QuotaTest FS-01 fixture relies on that and is order-dependent —
    // filed). Rebuilding the manager from the modified config does.
    config(['database.redis.volatile.host' => '127.0.0.1', 'database.redis.volatile.port' => 1]);
    app()->forgetInstance('redis');
    app('cache')->forgetDriver();

    try {
        expect(app(AiQuotaGate::class)->admit())->toBe('exhausted', 'unknown headroom was treated as open throttle');
        expect(fn () => app(AiOrchestrator::class)->execute('classify_message', [['role' => 'user', 'content' => '[FIXTURE] y']]))
            ->toThrow(AiQuotaExhausted::class);
        expect($driver->seen)->toBe([], 'a call was billed blind during the metering outage');
    } finally {
        config([
            'database.redis.volatile.host' => env('REDIS_VOLATILE_HOST', 'redis-volatile'),
            'database.redis.volatile.port' => env('REDIS_VOLATILE_PORT', '6379'),
        ]);
        app()->forgetInstance('redis');
        app('cache')->forgetDriver();
    }
});

it('NFR-06: the registered reserve and rate-card settings exist with platform validation; the rate card prices tokens', function (): void {
    $settings = require base_path('registries/settings.php');
    $reserve = collect($settings)->firstWhere('key', 'ai.quota.reserve_permille');
    $card = collect($settings)->firstWhere('key', 'ai.rate_card');
    expect($reserve['default'])->toBe(50)->and($reserve['tenant_editable'])->toBeFalse()
        ->and($card['type'])->toBe('json');

    $tenant = createTenant();
    bindTenant($tenant);
    app(SettingsRepository::class)->set('ai.rate_card', ['addis' => ['input_per_1k' => 0.5, 'output_per_1k' => 1.5, 'currency' => 'ETB']], tenantSurface: false);
    expect(app(App\Domain\Ai\Quota\CostCard::class)->costOf('addis', 2000, 1000))->toBe(['amount' => 2.5, 'currency' => 'ETB']);
});
