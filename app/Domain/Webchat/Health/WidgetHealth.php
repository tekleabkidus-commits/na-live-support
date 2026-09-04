<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Health;

use App\Models\Widget;
use App\Support\Logging\ScrubSecrets;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Doc 13 T-08 / FR-19 Health — the widget's per-day counters and sampled
 * beacon samples (SCHEMA AMENDMENT 7 items 4–5).
 *
 * C2 (countersigned): the day bucket is computed IN SQL from the DB clock at
 * the tenant's display timezone — never a PHP date. Increments are
 * `SET col = col + 1` inside the UPDATE (monotone by construction). The
 * blocked-origins list is DISPLAY-ONLY, bounded (≤ 50), last-writer-wins.
 * Samples are scrubbed by Doc 09 SEC-03's scrubber before write and only at
 * `widget.beacon.sample_rate`; counters ALWAYS move.
 */
final class WidgetHealth
{
    public const KINDS = ['load', 'open', 'blocked_origin', 'mount_fail', 'panel_error', 'double_install', 'message', 'invalid_signature'];

    private const COUNTER = [
        'load' => 'loads', 'open' => 'opens', 'message' => 'messages', 'panel_error' => 'errors',
        'invalid_signature' => 'invalid_signatures', 'blocked_origin' => 'blocked_origin_attempts',
        'mount_fail' => 'mount_fail_csp', 'double_install' => 'double_install',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ScrubSecrets $scrubber,
    ) {}

    public function beacon(Widget $widget, string $kind, ?string $reason, ?string $sample, ?string $bundleHash, ?string $origin): void
    {
        $column = self::COUNTER[$kind] ?? null;
        if ($column === null) {
            return;
        }
        if ($kind === 'mount_fail' && $reason !== 'csp') {
            $column = 'errors'; // only the CSP-blocked mount has its own counter (EC-02); other mount failures are errors
        }

        $tenantId = $widget->getAttribute('tenant_id');
        $tenantId = is_string($tenantId) ? $tenantId : '';
        $tzSetting = $this->settings->get('tenant.display_timezone');
        $tz = is_string($tzSetting) && $tzSetting !== '' ? $tzSetting : 'UTC';

        DB::transaction(function () use ($widget, $tenantId, $tz, $column, $kind, $origin): void {
            // The day bucket, in SQL, from the DB clock (C2 / C-11).
            /** @var object{day: string} $bucket */
            $bucket = DB::selectOne('SELECT (clock_timestamp() AT TIME ZONE ?)::date AS day', [$tz]);
            $day = $bucket->day;
            $affected = DB::table('widget_health_counters')
                ->where('widget_id', $widget->getKey())
                ->where('day', $day)
                ->update([$column => DB::raw("{$column} + 1"), 'updated_at' => DB::raw('clock_timestamp()')]);
            if ($affected === 0) {
                DB::table('widget_health_counters')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'widget_id' => $widget->getKey(),
                    'day' => $day, $column => 1,
                ]);
            }
            if ($kind === 'blocked_origin' && is_string($origin) && $origin !== '') {
                $this->recordBlockedOrigin($widget, $day, substr($origin, 0, 255));
            }
        });

        if ($sample !== null && $this->sampled()) {
            DB::table('widget_beacon_samples')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'widget_id' => $widget->getKey(),
                'kind' => in_array($kind, ['load', 'open', 'blocked_origin', 'mount_fail', 'panel_error', 'double_install'], true) ? $kind : 'panel_error',
                'reason' => $reason,
                'sample' => Str::limit($this->scrub($sample), 4096, ''),
                'bundle_hash' => $bundleHash,
            ]);
        }
    }

    private function recordBlockedOrigin(Widget $widget, string $day, string $origin): void
    {
        $row = DB::table('widget_health_counters')->where('widget_id', $widget->getKey())->where('day', $day)->first(['blocked_origins']);
        $list = is_string($row?->blocked_origins) ? json_decode($row->blocked_origins, true) : [];
        $list = is_array($list) ? $list : [];
        $found = false;
        foreach ($list as &$entry) {
            if (is_array($entry) && ($entry['origin'] ?? null) === $origin) {
                $count = $entry['count'] ?? 0;
                $entry['count'] = (is_int($count) ? $count : 0) + 1;
                $entry['last_at'] = now('UTC')->toIso8601String();
                $found = true;
            }
        }
        unset($entry);
        if (! $found) {
            $list[] = ['origin' => $origin, 'count' => 1, 'last_at' => now('UTC')->toIso8601String()];
        }
        $list = array_slice($list, -50); // bounded — display only, last-writer-wins (C2)
        DB::table('widget_health_counters')->where('widget_id', $widget->getKey())->where('day', $day)
            ->update(['blocked_origins' => json_encode($list, JSON_THROW_ON_ERROR)]);
    }

    /** The platform's ONE scrubber (phones, emails, secrets) — a Monolog processor, driven over a record. */
    private function scrub(string $sample): string
    {
        $record = ($this->scrubber)(new LogRecord(new \DateTimeImmutable, 'widget', Level::Info, $sample));

        return $record->message;
    }

    private function sampled(): bool
    {
        $rate = $this->settings->get('widget.beacon.sample_rate');
        $rate = is_numeric($rate) ? (float) $rate : 0.05;

        return $rate > 0 && (random_int(1, 1_000_000) / 1_000_000) <= $rate;
    }
}
