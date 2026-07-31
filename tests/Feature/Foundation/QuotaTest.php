<?php

declare(strict_types=1);

use App\Support\Cache\TenantCache;
use App\Support\Quota\Quota;
use App\Support\Quota\QuotaCheckUnavailable;
use App\Support\Quota\QuotaExceeded;
use App\Support\Quota\QuotaMeter;
use App\Support\Quota\QuotaSurface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * NFR-06 / EC-09 / FS-05 / SEC-05 — foundation quotas.
 *
 * Windows are wall-clock buckets; every windowed test uses ai_tokens_per_day, whose
 * 24-hour bucket cannot roll over inside a test run the way a minute bucket could.
 */
function currentDayBucket(): int
{
    return intdiv(time(), 86_400) * 86_400;
}

it('admits under the limit and refuses over it with a 429 naming the quota (NFR-06)', function () {
    createTenant(['quota_overrides' => ['quota.ai_tokens_per_day' => 3]]);
    $meter = app(QuotaMeter::class);

    $meter->allow(Quota::AiTokensPerDay, 3);

    $refused = null;
    try {
        $meter->allow(Quota::AiTokensPerDay, 1);
    } catch (QuotaExceeded $e) {
        $refused = $e;
    }

    expect($refused)->not->toBeNull();

    $response = $refused->render();
    expect($response->getStatusCode())->toBe(429)
        ->and($response->getData(true)['code'])->toBe('quota_exceeded')
        // NFR-06: "with the quota name" — a first-class member, not prose.
        ->and($response->getData(true)['quota'])->toBe('ai_tokens_per_day');
});

it('does not let a refused attempt consume capacity', function () {
    createTenant(['quota_overrides' => ['quota.ai_tokens_per_day' => 3]]);
    $meter = app(QuotaMeter::class);

    $meter->allow(Quota::AiTokensPerDay, 2);

    // 2 + 2 > 3: refused — and compensated, so the counter still reads 2, not 4.
    expect(fn () => $meter->allow(Quota::AiTokensPerDay, 2))->toThrow(QuotaExceeded::class);

    // The remaining unit is still available; a leaked increment would refuse this too.
    $meter->allow(Quota::AiTokensPerDay, 1);

    // And the counter reads exactly the admitted 3 units — the refused 2 left no trace.
    $suffix = sprintf('quota:%s:%d', Quota::AiTokensPerDay->value, currentDayBucket());
    expect((int) app(TenantCache::class)->get($suffix))->toBe(3);
});

it('raises the internal alert exactly once per window, at 80% (NFR-06)', function () {
    Log::spy();

    createTenant(['quota_overrides' => ['quota.ai_tokens_per_day' => 10]]);
    $meter = app(QuotaMeter::class);

    $meter->allow(Quota::AiTokensPerDay, 7); // 70% — quiet
    $meter->allow(Quota::AiTokensPerDay, 1); // 80% — the alert
    $meter->allow(Quota::AiTokensPerDay, 1); // 90% — still just the one alert

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => $message === 'tenant quota at 80 percent');
});

it('blocks forward writes and destroys nothing when a limit drops below current usage (EC-09)', function () {
    $tenant = createTenant(['quota_overrides' => ['quota.ai_tokens_per_day' => 10]]);
    $meter = app(QuotaMeter::class);

    $meter->allow(Quota::AiTokensPerDay, 6);

    // The platform lowers the ceiling below what is already counted.
    $tenant->forceFill(['quota_overrides' => ['quota.ai_tokens_per_day' => 3]])->save();

    expect(fn () => $meter->allow(Quota::AiTokensPerDay, 1))->toThrow(QuotaExceeded::class);

    // Nothing destructive happened: the counter still holds the 6 units of history.
    $suffix = sprintf('quota:%s:%d', Quota::AiTokensPerDay->value, currentDayBucket());
    expect((int) app(TenantCache::class)->get($suffix))->toBe(6);
});

it('enforces the cumulative storage quota from the durable ledger, in bytes against a GB limit', function () {
    $tenant = createTenant(['quota_overrides' => ['quota.storage_gb' => 1]]);
    $meter = app(QuotaMeter::class);

    // 0.9 GB already recorded by (future) media writers.
    DB::table('tenant_quota_usage')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->tenantId(),
        'quota' => Quota::StorageGb->value,
        'window_start' => '2000-01-01 00:00:00+00',
        'window_end' => '9999-01-01 00:00:00+00',
        'used' => (int) (0.9 * 1024 ** 3),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 0.05 GB more fits under the 1 GB ceiling; 0.2 GB does not.
    $meter->allow(Quota::StorageGb, (int) (0.05 * 1024 ** 3));
    expect(fn () => $meter->allow(Quota::StorageGb, (int) (0.2 * 1024 ** 3)))
        ->toThrow(QuotaExceeded::class);
});

it('persists counters to the ledger and repairs a flushed counter from it (FS-05)', function () {
    $tenant = createTenant(['quota_overrides' => ['quota.ai_tokens_per_day' => 100_000]]);
    $tenantId = $tenant->tenantId();
    commitFixtures();

    $suffix = sprintf('quota:%s:%d', Quota::AiTokensPerDay->value, currentDayBucket());
    $cacheKey = sprintf('t:%s:%s', $tenantId, $suffix);

    try {
        asTenant($tenantId, fn () => app(QuotaMeter::class)->allow(Quota::AiTokensPerDay, 500));

        // Reconcile #1: the counter lands in the durable ledger.
        app(TenantContext::class)->clear();
        app(App\Support\Tenancy\RlsSession::class)->clear();
        Artisan::call('quota:reconcile');

        $row = DB::connection('pgsql_platform')->table('tenant_quota_usage')
            ->where('tenant_id', $tenantId)
            ->where('quota', Quota::AiTokensPerDay->value)
            ->first();

        expect($row)->not->toBeNull()
            ->and((int) $row->used)->toBe(500)
            ->and($row->reconciled_at)->not->toBeNull();

        // FS-05 proper: the counter is FLUSHED — no key left, nothing for a scan to see.
        Cache::forget($cacheKey);
        expect(Cache::get($cacheKey))->toBeNull();

        // Reconcile #2 restores it from the ledger and records the investigated delta.
        Artisan::call('quota:reconcile');

        expect((int) Cache::get($cacheKey))->toBe(500);

        $row = DB::connection('pgsql_platform')->table('tenant_quota_usage')
            ->where('tenant_id', $tenantId)
            ->where('quota', Quota::AiTokensPerDay->value)
            ->first();
        expect((int) $row->last_drift)->toBe(-500);
    } finally {
        Cache::forget($cacheKey);
        Cache::forget($cacheKey.':warned');
        cleanupTenants([$tenantId]);
    }
});

it('fails OPEN for staff and CLOSED for public ingestion when metering is unreachable (FS-01)', function () {
    createTenant(['quota_overrides' => ['quota.ai_tokens_per_day' => 10]]);
    $meter = app(QuotaMeter::class);

    // A real connection failure, not a mock — same posture as the settings FS-01 proof.
    config([
        'database.redis.volatile.host' => '127.0.0.1',
        'database.redis.volatile.port' => 1,
    ]);
    app('redis')->purge('volatile');

    // Staff: degraded open — the call SUCCEEDS, unmetered, and that is the designed shape.
    $meter->allow(Quota::AiTokensPerDay, 1, QuotaSurface::Staff);

    // Public ingestion: refused until metering returns — and NOT as quota_exceeded,
    // because "we cannot count" must never masquerade as "you are over".
    expect(fn () => $meter->allow(Quota::AiTokensPerDay, 1, QuotaSurface::PublicIngestion))
        ->toThrow(QuotaCheckUnavailable::class);
});
