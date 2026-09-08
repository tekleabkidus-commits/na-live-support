<?php

declare(strict_types=1);

use App\Support\Cache\TenantCache;
use App\Support\Quota\Quota;
use App\Support\Quota\QuotaExceeded;
use App\Support\Quota\QuotaMeter;
use Illuminate\Support\Facades\DB;

/**
 * P-QUOTA-SMS (Doc 01 core, STOP-I; Doc 14 FR-19 "the Doc 01 quota system
 * caps monthly segments (sms.quota.segments) with the 80% alert") — the
 * controls, RED FIRST on the tree without it.
 *
 * `Quota` is enum-closed and its windows are 60 s / 86 400 s / cumulative;
 * `intdiv(time(), window)` cannot express a calendar month. The packet adds
 * `Quota::SmsSegmentsPerMonth` whose bucket is the TENANT-LOCAL calendar
 * month from DB time (`to_char(clock_timestamp() AT TIME ZONE tz, 'YYYY-MM')`,
 * C-11), keyed by the document's own setting `sms.quota.segments`.
 *
 * The advisor's condition: the four existing cases stay byte-identical in
 * behaviour — QuotaTest green unmodified PLUS the intdiv pin below.
 */
it('the four foundation quotas keep the intdiv wall-clock bucket EXACTLY (the pin the packet must not disturb)', function (): void {
    createTenant(['quota_overrides' => ['quota.ai_tokens_per_day' => 1000, 'quota.messages_per_minute' => 1000, 'quota.api_requests_per_minute' => 1000]]);
    $meter = app(QuotaMeter::class);
    $cache = app(TenantCache::class);

    foreach ([Quota::MessagesPerMinute, Quota::ApiRequestsPerMinute, Quota::AiTokensPerDay] as $quota) {
        $window = $quota->windowSeconds();
        expect($window)->toBeInt();
        $bucket = intdiv(time(), $window) * $window;

        $meter->allow($quota, 1);

        expect($cache->increment(sprintf('quota:%s:%d', $quota->value, $bucket), 0, $window * 2, 'quota.window_expired'))
            ->toBe(1, "{$quota->value}: the intdiv bucket key must be the one the meter wrote");
    }
    expect(Quota::StorageGb->windowSeconds())->toBeNull()
        ->and(Quota::MessagesPerMinute->settingKey())->toBe('quota.messages_per_minute');
});

it('Quota::SmsSegmentsPerMonth exists, is keyed by sms.quota.segments, and buckets by the tenant-local calendar month from DB time', function (): void {
    $tenant = createTenant(['quota_overrides' => ['sms.quota.segments' => 5]]);
    $meter = app(QuotaMeter::class);
    $cache = app(TenantCache::class);

    $quota = Quota::from('sms_segments_per_month');
    expect($quota->settingKey())->toBe('sms.quota.segments')
        ->and($quota->isCalendarMonth())->toBeTrue();

    $meter->allow($quota, 3);

    /** @var object{m: string} $row */
    $row = DB::selectOne("SELECT to_char(clock_timestamp() AT TIME ZONE ?, 'YYYY-MM') AS m", [$tenant->timezone_name]);
    expect($cache->increment(sprintf('quota:%s:%s', $quota->value, $row->m), 0, 35 * 86_400, 'quota.window_expired'))
        ->toBe(3, 'the month bucket key must carry the tenant-local YYYY-MM');

    $meter->allow($quota, 2);
    expect(fn () => $meter->allow($quota, 1))->toThrow(QuotaExceeded::class);

    expect($meter->headroom($quota))->toBe(['used' => 5, 'limit' => 5]);
});
