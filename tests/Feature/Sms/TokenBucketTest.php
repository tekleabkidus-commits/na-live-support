<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\Lane;
use App\Domain\Sms\Shaping\GatewayTokenBucket;
use App\Domain\Telegram\Outbound\TelegramRateLimiter;
use App\Support\Audit\AuditActor;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\Redis;

/*
| Doc 14 T-04 / NFR-02 / BR-05 / FS-05 — the per-gateway token bucket, MIRRORED
| from Doc 05's Lua (STOP-K): the drift pin (byte-identical scripts), the
| conformance legs re-targeted (burst then pace; refill restores), the lane
| reserve (the broadcast lane cannot drain the tokens the transactional lane
| needs), and FS-05 (a lost bucket starts at HALF capacity and ramps).
*/

function smsBucket(): GatewayTokenBucket
{
    $tenant = createTenant();
    bindTenant($tenant);
    Redis::connection('volatile')->command('flushdb');

    return app(GatewayTokenBucket::class);
}

it('drift pin: the mirrored Lua is byte-identical to TelegramRateLimiter::LUA (the mirror cannot diverge silently)', function (): void {
    $source = (new ReflectionClassConstant(TelegramRateLimiter::class, 'LUA'))->getValue();

    expect(GatewayTokenBucket::LUA)->toBe($source);
});

it('conformance: a 5/s gateway allows a burst up to capacity, refuses the next in the same instant, and refills at the rate', function (): void {
    $bucket = smsBucket();
    $now = 1_000_000_000.0;
    // FS-05: a fresh (lost) bucket starts at HALF capacity — 2 of 5 — never a full burst
    $allowed = 0;
    for ($i = 0; $i < 5; $i++) {
        $allowed += $bucket->allow('gw-1', 5, Lane::Transactional, $now) ? 1 : 0;
    }
    expect($allowed)->toBe(2, 'FS-05: half capacity after a loss');
    // one second later the rate refilled 5 tokens (capped at capacity)
    $allowedLater = 0;
    for ($i = 0; $i < 7; $i++) {
        $allowedLater += $bucket->allow('gw-1', 5, Lane::Transactional, $now + 1.0) ? 1 : 0;
    }
    expect($allowedLater)->toBe(5);
    expect($bucket->allow('gw-1', 5, Lane::Transactional, $now + 1.0))->toBeFalse('over-limit is a WAIT (false), never a drop');
    expect($bucket->allow('gw-1', 5, Lane::Transactional, $now + 1.2))->toBeTrue('0.2 s × 5/s = one token refilled');
});

it('BR-05: the broadcast lane passes BOTH buckets — the reserve fraction keeps transactional headroom on the same gateway', function (): void {
    $bucket = smsBucket();
    app(SettingsRepository::class)->set('sms.lane.reserve_fraction', '0.4', AuditActor::PlatformAdmin, tenantSurface: false); // platform-owned (registry): a platform write, never a tenant surface
    $now = 2_000_000_000.0;
    // warm both buckets past the FS-05 half start: two seconds of refill
    $bucket->allow('gw-2', 10, Lane::Broadcast, $now);
    $t = $now + 2.0;
    $broadcasts = 0;
    for ($i = 0; $i < 20; $i++) {
        $broadcasts += $bucket->allow('gw-2', 10, Lane::Broadcast, $t) ? 1 : 0;
    }
    expect($broadcasts)->toBe(6, 'the broadcast sub-bucket is 10 × (1 − 0.4) = 6 in that second');
    // the transactional lane still finds the remaining gateway tokens the broadcast lane could not take
    $transactional = 0;
    for ($i = 0; $i < 10; $i++) {
        $transactional += $bucket->allow('gw-2', 10, Lane::Transactional, $t) ? 1 : 0;
    }
    expect($transactional)->toBe(4, 'a resolved-notification never waits behind an announcement');
    expect($bucket->broadcastRate(10))->toBe(6);
});
