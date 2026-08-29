<?php

declare(strict_types=1);

namespace App\Domain\Kb\Analytics;

use App\Domain\Kb\Feedback\VisitorLimiter;
use Illuminate\Support\Facades\DB;

/**
 * Doc 10 §7 stats header / AC-08 (S10 step 10): per-article facts read from the EVENTS table
 * (the bus's durable record — the same rows Doc 15 consumes, so the header and the report cannot
 * disagree on inputs) plus the feedback rows. Views by surface, AI citations, agent inserts,
 * helpful % (customer scores only — staff notes are not votes), and the DEFLECTION INPUTS:
 * views/citations carrying a conversation_id with NO ticket created in that conversation within
 * 24 h. The deflection FORMULA (weights, windows, attribution rules) is Doc 15's ([D15]); this
 * header exposes `deflection_candidates` and says so. Reconciled against fixtures/kb/analytics/
 * doc15-stats.json — the fixture Doc 15 inherits as its input contract.
 */
final class ArticleStats
{
    public function __construct(private readonly VisitorLimiter $limiter) {}

    /**
     * @return array{views: array<string, int>, citations: int, inserts: int, feedback: array{up: int, down: int, helpful_pct: int|null, comments: int}, deflection_candidates: int, deflection_formula: string, anomalies_today: int}
     */
    public function forArticle(string $articleId): array
    {
        $views = ['widget' => 0, 'telegram' => 0, 'staff' => 0, 'copilot' => 0];
        /** @var list<object{surface: string, n: int|string}> $viewRows */
        $viewRows = DB::select("SELECT payload->>'surface' AS surface, count(*) AS n FROM events WHERE name = 'kb.article.viewed' AND payload->>'article_id' = ? GROUP BY 1", [$articleId]);
        foreach ($viewRows as $row) {
            if (array_key_exists($row->surface, $views)) {
                $views[$row->surface] = (int) $row->n;
            }
        }
        $citations = (int) DB::table('events')->where('name', 'kb.article.cited_by_ai')->where('payload->article_id', $articleId)->count();
        $inserts = (int) DB::table('events')->where('name', 'kb.article.inserted_by_agent')->where('payload->article_id', $articleId)->count();

        /** @var object{up: int|string, down: int|string, comments: int|string} $fb */
        $fb = DB::selectOne('SELECT count(*) FILTER (WHERE score = 1) AS up, count(*) FILTER (WHERE score = -1) AS down, count(*) FILTER (WHERE comment IS NOT NULL) AS comments FROM kb_feedback WHERE article_id = ? AND visitor_hash IS NOT NULL', [$articleId]);
        $up = (int) $fb->up;
        $down = (int) $fb->down;

        // Deflection INPUTS: a view or citation in a conversation with no ticket created within 24 h of it.
        /** @var object{n: int|string} $defl */
        $defl = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n
              FROM events e
             WHERE e.name IN ('kb.article.viewed', 'kb.article.cited_by_ai')
               AND e.payload->>'article_id' = ?
               AND e.payload->>'conversation_id' IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM tickets t
                    WHERE t.conversation_id = (e.payload->>'conversation_id')::uuid
                      AND t.created_at BETWEEN e.occurred_at AND e.occurred_at + interval '24 hours'
               )
            SQL, [$articleId]);

        return [
            'views' => $views,
            'citations' => $citations,
            'inserts' => $inserts,
            'feedback' => ['up' => $up, 'down' => $down, 'helpful_pct' => ($up + $down) === 0 ? null : (int) round(100 * $up / ($up + $down)), 'comments' => (int) $fb->comments],
            'deflection_candidates' => (int) $defl->n,
            'deflection_formula' => 'doc15', // the weights/windows are Doc 15's; this header ships the inputs
            'anomalies_today' => $this->limiter->anomaliesToday(),
        ];
    }

    /**
     * §7: the feedback list, cursor-paginated (C-05), newest first; comments are stored inert.
     *
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function feedbackList(string $articleId, ?string $cursor, int $limit = 50): array
    {
        $limit = min(max($limit, 1), 100);
        $q = DB::table('kb_feedback')->where('article_id', $articleId)->orderByDesc('id')->limit($limit + 1);
        if (is_string($cursor) && $cursor !== '') {
            $q->where('id', '<', $cursor);
        }
        $rows = $q->get(['id', 'variant_id', 'source', 'score', 'comment', 'staff_id', 'actioned_at', 'created_at'])->all();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        /** @var list<array<string, mixed>> $data */
        $data = array_map(static fn (object $r): array => (array) $r, $rows);
        $last = $rows === [] ? null : end($rows);
        $next = $more && is_object($last) && is_string($last->id) ? $last->id : null;

        return ['data' => $data, 'next_cursor' => $next];
    }
}
