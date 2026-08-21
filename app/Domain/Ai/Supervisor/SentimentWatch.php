<?php

declare(strict_types=1);

namespace App\Domain\Ai\Supervisor;

use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * FR-17 live sentiment watch: a conversation whose LATEST classification
 * crosses the very-negative threshold becomes a supervisor FEED ITEM (one
 * row per conversation per 24 h — a customer who stays angry is one item,
 * not one per message). Arithmetic over the decision log; spam excluded
 * (N-14); the item carries identifiers + the score, never the text.
 */
final class SentimentWatch
{
    public function __construct(
        private readonly SupervisorLog $log,
        private readonly SettingsRepository $settings,
        private readonly SupervisorGate $gate,
    ) {}

    /** @return array{paused: ?string, items: list<array{conversation_id: string, sentiment: int, intent: ?string, decision_id: string}>} */
    public function run(): array
    {
        if (($paused = $this->gate->paused('fast')) !== null) {
            return ['paused' => $paused, 'items' => []];
        }

        $raw = $this->settings->get('ai.supervisor.very_negative_sentiment');
        $threshold = is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : -2);

        /** @var list<object{conversation_id: string, sentiment: string|int, intent: ?string}> $rows */
        $rows = DB::select(<<<'SQL'
            WITH latest AS (
                SELECT DISTINCT ON (conversation_id) conversation_id, intent,
                       (response_payload->'classification'->>'sentiment')::int AS sentiment
                  FROM ai_decisions
                 WHERE task = 'classify_message' AND conversation_id IS NOT NULL
                   AND jsonb_exists(response_payload, 'classification')
                   AND created_at >= clock_timestamp() - interval '1 hour'
                 ORDER BY conversation_id, created_at DESC
            )
            SELECT l.conversation_id, l.sentiment, l.intent
              FROM latest l
             WHERE l.sentiment <= ? AND coalesce(l.intent, '') <> 'spam'
               AND NOT EXISTS (
                   SELECT 1 FROM ai_decisions w
                    WHERE w.surface = 'supervisor' AND w.action = 'sentiment_watch'
                      AND w.conversation_id = l.conversation_id
                      AND w.created_at >= clock_timestamp() - interval '24 hours'
               )
        SQL, [$threshold]);

        $items = [];
        foreach ($rows as $row) {
            $id = $this->log->write('anomaly_scan', 'sentiment_watch', ['sentiment' => (int) $row->sentiment, 'intent' => $row->intent, 'threshold' => $threshold], $row->conversation_id);
            $items[] = ['conversation_id' => $row->conversation_id, 'sentiment' => (int) $row->sentiment, 'intent' => $row->intent, 'decision_id' => $id];
        }

        return ['paused' => null, 'items' => $items];
    }
}
