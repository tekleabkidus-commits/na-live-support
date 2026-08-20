<?php

declare(strict_types=1);

namespace App\Domain\Ai\Rg;

use Illuminate\Support\Facades\DB;

/**
 * EC-06's CALIBRATION CONSUMPTION (Doc 08 EC-06 — the [D09] close): the
 * FR-20 RG precision review queue, computed from the two existing stores —
 * rg_flag_history (every AI fire + every human clear WITH its reason, the
 * consumable Doc 08 stored) JOINed with ai_decisions (the score that
 * fired). No new table (the countersigned non-table). Read-model only.
 */
final class RgCalibration
{
    /**
     * @return list<array{customer_id: string, fired_at: string, ai_score: ?float, cleared_at: ?string, clear_reason: ?string, decision_id: ?string}>
     */
    public function reviewQueue(int $limit = 100): array
    {
        /** @var list<object{customer_id: string, fired_at: string, decision_id: ?string, ai_score: ?string, cleared_at: ?string, clear_reason: ?string}> $rows */
        $rows = DB::select(
            <<<'SQL'
            SELECT fire.customer_id,
            fire.created_at::text            AS fired_at,
            fire.excerpt_ref->>'decision_id' AS decision_id,
            d.rg_risk                        AS ai_score,
            clr.created_at::text             AS cleared_at,
            clr.reason                       AS clear_reason
            FROM rg_flag_history fire
            LEFT JOIN LATERAL (
            SELECT c.created_at, c.reason
            FROM rg_flag_history c
            WHERE c.customer_id = fire.customer_id
            AND c.action = 'cleared'
            AND c.created_at > fire.created_at
            ORDER BY c.created_at
            LIMIT 1
            ) clr ON true
            LEFT JOIN ai_decisions d
            ON d.id::text = fire.excerpt_ref->>'decision_id'
            WHERE fire.action = 'set' AND fire.source = 'ai'
            ORDER BY fire.created_at DESC
            LIMIT ?

            SQL,
            [$limit],
        );

        $queue = [];
        foreach ($rows as $row) {
            $queue[] = [
                'customer_id' => $row->customer_id,
                'fired_at' => $row->fired_at,
                'ai_score' => is_numeric($row->ai_score) ? (float) $row->ai_score : null,
                'cleared_at' => $row->cleared_at,
                'clear_reason' => $row->clear_reason,
                'decision_id' => $row->decision_id,
            ];
        }

        return $queue;
    }
}
