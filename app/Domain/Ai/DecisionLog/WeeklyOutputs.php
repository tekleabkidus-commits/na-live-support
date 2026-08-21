<?php

declare(strict_types=1);

namespace App\Domain\Ai\DecisionLog;

use App\Domain\Ai\Rg\RgCalibration;
use Illuminate\Support\Facades\DB;

/**
 * FR-20's weekly automated outputs, as READ MODELS over the decision log
 * (the FR-16/FR-17 posture: no second store; Doc 15 renders [D15]):
 *
 *  topUnanswerable()  — the top-10 gaps of the last 7 days: no_grounding
 *      handoffs + EC-09's locked regenerations, grouped by intent, with
 *      the message ids as the "draft a KB article from these conversations"
 *      seam ([D10]: Doc 10's one-click draft consumes the ids, never text
 *      from here).
 *  calibrationCurve() — confidence buckets (10) vs outcome over 30 days:
 *      resolved / resolved_then_reopened / handoff / discarded counts per
 *      bucket — the honest curve (R-06's triad is Doc 15's rendering).
 *  rgPrecisionQueue() — delegates to RgCalibration (the [D09] EC-06 close).
 */
final class WeeklyOutputs
{
    public function __construct(private readonly RgCalibration $rg) {}

    /** @return list<array{intent: string, count: int, no_grounding: int, locked: int, message_ids: list<string>, sample_conversation_ids: list<string>}> */
    public function topUnanswerable(int $limit = 10): array
    {
        /** @var list<object{intent: ?string, kind: string, message_id: ?string, conversation_id: ?string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT intent, 'no_grounding' AS kind, message_id, conversation_id
              FROM ai_decisions
             WHERE surface = 'chatbot' AND action = 'handoff'
               AND response_payload->>'handoff_reason' = 'no_grounding'
               AND created_at >= clock_timestamp() - interval '7 days'
            UNION ALL
            SELECT d.intent, 'locked' AS kind, d.message_id, d.conversation_id
              FROM ai_decisions d
             WHERE d.surface = 'copilot' AND d.action = 'low_confidence_locked'
               AND d.created_at >= clock_timestamp() - interval '7 days'
        SQL);

        /** @var array<string, array{intent: string, count: int, no_grounding: int, locked: int, message_ids: list<string>, sample_conversation_ids: list<string>}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $intent = $row->intent ?? 'other';
            if (! isset($groups[$intent])) {
                $groups[$intent] = ['intent' => $intent, 'count' => 0, 'no_grounding' => 0, 'locked' => 0, 'message_ids' => [], 'sample_conversation_ids' => []];
            }
            $groups[$intent]['count']++;
            if ($row->kind === 'locked') {
                $groups[$intent]['locked']++;
            } else {
                $groups[$intent]['no_grounding']++;
            }
            if (is_string($row->message_id)) {
                $groups[$intent]['message_ids'][] = $row->message_id;
            }
            if (is_string($row->conversation_id) && count($groups[$intent]['sample_conversation_ids']) < 5 && ! in_array($row->conversation_id, $groups[$intent]['sample_conversation_ids'], true)) {
                $groups[$intent]['sample_conversation_ids'][] = $row->conversation_id;
            }
        }
        $list = array_values($groups);
        usort($list, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($list, 0, $limit);
    }

    /** @return list<array{bucket: string, total: int, resolved: int, reopened: int, handoff: int, discarded: int}> */
    public function calibrationCurve(): array
    {
        /** @var list<object{bucket: int|string, total: int|string, resolved: int|string, reopened: int|string, handoff: int|string, discarded: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT least(9, floor(confidence * 10))::int AS bucket,
                   count(*) AS total,
                   count(*) FILTER (WHERE outcome = 'resolved') AS resolved,
                   count(*) FILTER (WHERE outcome = 'resolved_then_reopened') AS reopened,
                   count(*) FILTER (WHERE action = 'handoff') AS handoff,
                   count(*) FILTER (WHERE outcome = 'discarded') AS discarded
              FROM ai_decisions
             WHERE confidence IS NOT NULL
               AND surface IN ('chatbot', 'copilot')
               AND created_at >= clock_timestamp() - interval '30 days'
             GROUP BY 1
             ORDER BY 1
        SQL);

        $curve = [];
        foreach ($rows as $row) {
            $b = (int) $row->bucket;
            $curve[] = ['bucket' => sprintf('%.1f-%.1f', $b / 10, ($b + 1) / 10), 'total' => (int) $row->total, 'resolved' => (int) $row->resolved, 'reopened' => (int) $row->reopened, 'handoff' => (int) $row->handoff, 'discarded' => (int) $row->discarded];
        }

        return $curve;
    }

    /** @return list<array<string, mixed>> */
    public function rgPrecisionQueue(int $limit = 100): array
    {
        return $this->rg->reviewQueue($limit);
    }
}
