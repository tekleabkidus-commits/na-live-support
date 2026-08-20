<?php

declare(strict_types=1);

namespace App\Domain\Ai\Retrieval;

use App\Domain\Ai\Orchestrator\AiOrchestrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * T-05/FR-10: HYBRID retrieval over kb_chunks — vector (pgvector cosine over
 * the HNSW index) + keyword (tsvector rank, 'simple' config) with the
 * language filter and Doc 01's fallback chain (customer language → tenant
 * default → en), PUBLISHED + AI-usable + retrievable only; `internal`
 * chunks serve COPILOT tasks only, never the chatbot (FR-10). When no query
 * vector is available (sidecar down, FS-02 posture) the keyword half
 * serves alone and the result says so — the caller's grounding rule does
 * the rest (no citation, no answer).
 */
final class HybridRetriever
{
    public function __construct(private readonly AiOrchestrator $orchestrator) {}

    /**
     * @param  list<string>  $languages  the fallback chain, most specific first
     * @return array{chunks: list<array{article_ref: string, content: string, score: float}>, degraded: bool}
     */
    public function retrieve(string $query, array $languages, bool $includeInternal = false, int $topK = 5): array
    {
        $vector = null;
        try {
            $embedded = $this->orchestrator->executeEmbeddings([$query]);
            $vector = $embedded[0] ?? null;
        } catch (Throwable) {
            $vector = null;
        }

        $builder = DB::table('kb_chunks')
            ->where('retrievable', true)
            ->where('ai_usable', true)
            ->whereIn('language', $languages === [] ? ['en'] : $languages);

        if (! $includeInternal) {
            $builder->where('audience', 'public');
        }

        $keywordRank = "ts_rank(content_tsv, plainto_tsquery('simple', ?))";

        if ($vector !== null) {
            $literal = '['.implode(',', $vector).']';
            $builder->selectRaw(
                "article_ref, content, language, (0.6 * COALESCE(1 - (embedding <=> ?::vector), 0) + 0.4 * {$keywordRank}) AS score",
                [$literal, $query],
            );
        } else {
            $builder->selectRaw("article_ref, content, language, {$keywordRank} AS score", [$query]);
        }

        /** @var list<object{article_ref: string, content: string, language: string, score: string|float}> $rows */
        $rows = $builder->orderByDesc('score')->limit($topK * 3)->get()->all();

        // Language preference inside the chain: the most specific language
        // wins ties (the fallback chain is a net, not a steady state — R-24).
        $rank = array_flip($languages === [] ? ['en'] : $languages);
        usort($rows, static function (object $a, object $b) use ($rank): int {
            $scoreCmp = (float) $b->score <=> (float) $a->score;

            return $scoreCmp !== 0 ? $scoreCmp : (($rank[$a->language] ?? 99) <=> ($rank[$b->language] ?? 99));
        });

        $chunks = [];
        foreach (array_slice($rows, 0, $topK) as $row) {
            if ((float) $row->score <= 0.0) {
                continue;
            }
            $chunks[] = ['article_ref' => $row->article_ref, 'content' => $row->content, 'score' => (float) $row->score];
        }

        return ['chunks' => $chunks, 'degraded' => $vector === null];
    }
}
