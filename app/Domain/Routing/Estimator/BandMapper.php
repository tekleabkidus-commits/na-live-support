<?php

declare(strict_types=1);

namespace App\Domain\Routing\Estimator;

use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Doc 11 FR-13 / NFR-04 / FS-03 — the BANDED MAPPER with hysteresis (A-52, filed).
 *
 * Bands (the frozen J15 vocabulary): raw < short → `short` ("~5 min"); short..medium →
 * `medium` ("~20 min"); > medium → `long` ("over an hour"); the thresholds are the platform
 * setting `routing.estimator.band_thresholds_minutes`. Outside hours → `off_hours` (the
 * caller decides from HoursContract; no hysteresis — it is a calendar fact).
 *
 * Hysteresis (NFR-04): the band a queue shows changes ONLY when the raw estimate crosses
 * the boundary by ≥ `routing.estimator.hysteresis_pct` of the band's width AND ≥ 30 s
 * have passed since the last change (DB time, passed in). The shown band and its change
 * instant live on redis-volatile (`t:{tenant}:routing:est:band:{queue}`, TTL one hour —
 * a lost key means one honest recompute, never a wrong band).
 *
 * Raw estimate: `position × 30 / throughput30` minutes. No throughput data (zero
 * assignments in the window) → the DEPTH-ONLY HEURISTIC: `DEPTH_ONLY_MINUTES_PER_TICKET`
 * per position — the same heuristic FS-03 falls to when counters are corrupted, which is
 * when `degraded` is true (A-52: "depth-only heuristic + degraded: true, same vocabulary").
 */
final class BandMapper
{
    public const DEPTH_ONLY_MINUTES_PER_TICKET = 10;

    private const MIN_SECONDS_BETWEEN_CHANGES = 30;

    private const STATE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly TenantContext $context,
        private readonly SettingsRepository $settings,
        private readonly RedisFactory $redis,
    ) {}

    /** @return array{short: int, medium: int} */
    public function thresholds(): array
    {
        $raw = $this->settings->get('routing.estimator.band_thresholds_minutes');
        $short = is_array($raw) && is_numeric($raw['short'] ?? null) ? (int) $raw['short'] : 8;
        $medium = is_array($raw) && is_numeric($raw['medium'] ?? null) ? (int) $raw['medium'] : 35;

        return ['short' => $short, 'medium' => max($medium, $short + 1)];
    }

    /** The raw estimate in minutes for a 1-based position, or the depth-only heuristic. */
    public function rawMinutes(int $position, ?int $throughput30): float
    {
        $position = max(1, $position);
        if ($throughput30 === null || $throughput30 <= 0) {
            return $position * self::DEPTH_ONLY_MINUTES_PER_TICKET;
        }

        return $position * Counters::WINDOW_MINUTES / $throughput30;
    }

    /** The band a raw estimate falls in, with no memory. */
    public function bandFor(float $rawMinutes): string
    {
        $t = $this->thresholds();
        if ($rawMinutes < $t['short']) {
            return 'short';
        }

        return $rawMinutes <= $t['medium'] ? 'medium' : 'long';
    }

    /**
     * The band to SHOW for a queue now — hysteresis applied against the last shown band.
     */
    public function shownBand(string $queueId, float $rawMinutes, int $nowEpochSeconds): string
    {
        $candidate = $this->bandFor($rawMinutes);
        $key = sprintf('t:%s:routing:est:band:%s', $this->context->tenantId(), $queueId);
        $redis = $this->redis->connection('volatile');
        $state = $redis->command('get', [$key]);
        $last = is_string($state) ? json_decode($state, true) : null;
        $lastBand = is_array($last) && is_string($last['band'] ?? null) ? $last['band'] : null;
        $changedAt = is_array($last) && is_numeric($last['changed_at'] ?? null) ? (int) $last['changed_at'] : 0;

        if ($lastBand === null) {
            $redis->command('set', [$key, json_encode(['band' => $candidate, 'changed_at' => $nowEpochSeconds], JSON_THROW_ON_ERROR), ['EX' => self::STATE_TTL_SECONDS]]);

            return $candidate;
        }
        if ($candidate === $lastBand) {
            return $lastBand;
        }
        if ($nowEpochSeconds - $changedAt < self::MIN_SECONDS_BETWEEN_CHANGES) {
            return $lastBand;
        }
        if (! $this->crossedWithMargin($lastBand, $rawMinutes)) {
            return $lastBand;
        }
        $redis->command('set', [$key, json_encode(['band' => $candidate, 'changed_at' => $nowEpochSeconds], JSON_THROW_ON_ERROR), ['EX' => self::STATE_TTL_SECONDS]]);

        return $candidate;
    }

    /** Has the raw estimate left `$fromBand` by at least hysteresis_pct of that band's width? */
    private function crossedWithMargin(string $fromBand, float $rawMinutes): bool
    {
        $t = $this->thresholds();
        $pct = $this->settings->get('routing.estimator.hysteresis_pct');
        $pct = is_numeric($pct) ? (int) $pct : 20;
        $shortWidth = $t['short'];
        $mediumWidth = $t['medium'] - $t['short'];

        return match ($fromBand) {
            'short' => $rawMinutes >= $t['short'] + $shortWidth * $pct / 100,
            'medium' => $rawMinutes < $t['short'] - $mediumWidth * $pct / 100 || $rawMinutes > $t['medium'] + $mediumWidth * $pct / 100,
            'long' => $rawMinutes <= $t['medium'] - $mediumWidth * $pct / 100,
            default => true,
        };
    }

    /** Test/heal hook: forget the shown-band memory for a queue. */
    public function forget(string $queueId): void
    {
        $this->redis->connection('volatile')->command('del', [sprintf('t:%s:routing:est:band:%s', $this->context->tenantId(), $queueId)]);
    }
}
