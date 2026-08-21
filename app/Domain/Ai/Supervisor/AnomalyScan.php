<?php

declare(strict_types=1);

namespace App\Domain\Ai\Supervisor;

use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * FR-17 anomaly_scan (hourly): volume / intent / sentiment DELTAS vs
 * baseline → alerts ("withdrawal complaints +240% since 14:00").
 *
 * FILED INTERPRETATION: the deltas are ARITHMETIC over the classification
 * rows (the decision log is the source, N-14: spam-classified rows are
 * excluded), never a model's opinion — the alert text is an i18n TEMPLATE
 * keyed by the numbers (N-03), so an alert can be reproduced from the
 * aggregates and costs nothing. Baseline = the same clock hour's mean over
 * the prior 7 days (shift-shaped traffic compared like with like). Gates:
 * a minimum current volume (noise floor) and a delta threshold, both
 * registered settings. Window boundaries are DB time (C-11).
 */
final class AnomalyScan
{
    public function __construct(
        private readonly SupervisorLog $log,
        private readonly SettingsRepository $settings,
        private readonly SupervisorGate $gate,
    ) {}

    /**
     * @return array{paused: ?string, alerts: list<array<string, mixed>>, decision_id: ?string}
     */
    public function run(): array
    {
        if (($paused = $this->gate->paused('fast')) !== null) {
            $this->log->write('anomaly_scan', 'paused', ['reason' => $paused]);

            return ['paused' => $paused, 'alerts' => [], 'decision_id' => null];
        }

        $minVolume = $this->int('ai.supervisor.anomaly_min_volume', 5);
        $deltaPct = $this->int('ai.supervisor.anomaly_delta_pct', 200);

        /** @var list<object{intent: string, current: int|string, baseline: string|float|null}> $rows */
        $rows = DB::select(<<<'SQL'
            WITH win AS (
                SELECT date_trunc('hour', clock_timestamp()) - interval '1 hour' AS start_at,
                       date_trunc('hour', clock_timestamp()) AS end_at
            ),
            cur AS (
                SELECT intent, count(*) AS n
                  FROM ai_decisions, win
                 WHERE task = 'classify_message' AND intent IS NOT NULL AND intent <> 'spam'
                   AND created_at >= win.start_at AND created_at < win.end_at
                 GROUP BY intent
            ),
            base AS (
                SELECT intent, count(*) / 7.0 AS n
                  FROM ai_decisions, win
                 WHERE task = 'classify_message' AND intent IS NOT NULL AND intent <> 'spam'
                   AND created_at >= win.start_at - interval '7 days' AND created_at < win.start_at
                   AND extract(hour from created_at) = extract(hour from win.start_at)
                 GROUP BY intent
            )
            SELECT coalesce(cur.intent, base.intent) AS intent, coalesce(cur.n, 0) AS current, coalesce(base.n, 0) AS baseline
              FROM cur FULL OUTER JOIN base ON base.intent = cur.intent
        SQL);

        $sentiment = DB::selectOne(<<<'SQL'
            WITH win AS (
                SELECT date_trunc('hour', clock_timestamp()) - interval '1 hour' AS start_at,
                       date_trunc('hour', clock_timestamp()) AS end_at
            )
            SELECT avg((response_payload->'classification'->>'sentiment')::numeric) FILTER (WHERE created_at >= win.start_at AND created_at < win.end_at) AS current,
                   avg((response_payload->'classification'->>'sentiment')::numeric) FILTER (WHERE created_at >= win.start_at - interval '7 days' AND created_at < win.start_at) AS baseline,
                   to_char(win.start_at AT TIME ZONE 'UTC', 'HH24:MI') AS since
              FROM ai_decisions, win
             WHERE task = 'classify_message' AND intent <> 'spam' AND jsonb_exists(response_payload, 'classification')
             GROUP BY win.start_at
        SQL);

        $since = is_object($sentiment) && is_string($sentiment->since ?? null) ? $sentiment->since : '00:00';
        $alerts = [];
        $volumeCurrent = 0;
        $volumeBaseline = 0.0;
        foreach ($rows as $row) {
            $current = (int) $row->current;
            $baseline = (float) ($row->baseline ?? 0);
            $volumeCurrent += $current;
            $volumeBaseline += $baseline;
            if ($current < $minVolume) {
                continue;
            }
            $pct = $baseline <= 0 ? null : (int) round((($current - $baseline) / $baseline) * 100);
            if ($pct === null || $pct >= $deltaPct) {
                $alerts[] = ['kind' => 'intent', 'intent' => $row->intent, 'current' => $current, 'baseline' => round($baseline, 1), 'delta_pct' => $pct, 'since' => $since,
                    'i18n' => ['key' => $pct === null ? 'ai.supervisor.anomaly.intent_new' : 'ai.supervisor.anomaly.intent_delta', 'params' => ['intent' => $row->intent, 'pct' => $pct, 'since' => $since]]];
            }
        }
        if ($volumeCurrent >= $minVolume && $volumeBaseline > 0) {
            $pct = (int) round((($volumeCurrent - $volumeBaseline) / $volumeBaseline) * 100);
            if ($pct >= $deltaPct) {
                $alerts[] = ['kind' => 'volume', 'current' => $volumeCurrent, 'baseline' => round($volumeBaseline, 1), 'delta_pct' => $pct, 'since' => $since,
                    'i18n' => ['key' => 'ai.supervisor.anomaly.volume_delta', 'params' => ['pct' => $pct, 'since' => $since]]];
            }
        }
        if (is_object($sentiment) && is_numeric($sentiment->current ?? null) && is_numeric($sentiment->baseline ?? null) && $volumeCurrent >= $minVolume) {
            $drop = (float) $sentiment->baseline - (float) $sentiment->current;
            if ($drop >= 1.0) { // a full point on the −2..+2 scale
                $alerts[] = ['kind' => 'sentiment', 'current' => round((float) $sentiment->current, 2), 'baseline' => round((float) $sentiment->baseline, 2), 'since' => $since,
                    'i18n' => ['key' => 'ai.supervisor.anomaly.sentiment_drop', 'params' => ['drop' => round($drop, 1), 'since' => $since]]];
            }
        }

        $decisionId = $this->log->write('anomaly_scan', 'anomaly_scan', [
            'since' => $since, 'volume' => ['current' => $volumeCurrent, 'baseline' => round($volumeBaseline, 1)],
            'alerts' => $alerts, 'thresholds' => ['min_volume' => $minVolume, 'delta_pct' => $deltaPct],
        ]);

        return ['paused' => null, 'alerts' => $alerts, 'decision_id' => $decisionId];
    }

    private function int(string $key, int $default): int
    {
        $raw = $this->settings->get($key);

        return is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : $default);
    }
}
