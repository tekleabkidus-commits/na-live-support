<?php

declare(strict_types=1);

namespace App\Domain\Ai\Retrieval;

use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Prompt\InstructionStripper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * T-05/T-03/FR-10: the chunk INDEXER — Doc 10's publish events will drive it
 * ([D10]; fixture-fed here). Index time is where T-03's instruction
 * stripping happens (once, with provenance), where the embedding is
 * computed (ADR 0018's sidecar via the embeddings tier; absence is
 * tolerated — a chunk without a vector still serves the keyword half), and
 * where audience/ai_usable/language land. Upsert on the (tenant, article,
 * chunk_index) arbiter — the only non-PK unique (the arbiter law).
 */
final class KbIndexer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InstructionStripper $stripper,
        private readonly AiOrchestrator $orchestrator,
    ) {}

    /**
     * @param  list<float>|null  $fixtureVector  deterministic vector for proofs (ADR 0018's split)
     * @return string the chunk id
     */
    public function index(string $articleRef, int $chunkIndex, string $language, string $rawContent, string $audience = 'public', bool $aiUsable = true, ?array $fixtureVector = null): string
    {
        $stripped = $this->stripper->strip($rawContent);
        $vector = $fixtureVector ?? $this->embed($stripped['content']);

        $row = [
            'tenant_id' => $this->context->tenantId(),
            'article_ref' => $articleRef,
            'chunk_index' => $chunkIndex,
            'language' => $language,
            'audience' => $audience,
            'ai_usable' => $aiUsable,
            'content' => $stripped['content'],
            'provenance' => json_encode(['article_ref' => $articleRef, 'stripped_patterns' => $stripped['stripped'], 'source' => 'fixture-fed ([D10])'], JSON_THROW_ON_ERROR),
            'embedding' => $vector === null ? null : '['.implode(',', $vector).']',
            'retrievable' => true,
            'updated_at' => DB::raw('clock_timestamp()'),
        ];

        $existing = DB::table('kb_chunks')->where('article_ref', $articleRef)->where('chunk_index', $chunkIndex)->value('id');
        if (is_string($existing)) {
            DB::table('kb_chunks')->where('id', $existing)->update($row);

            return $existing;
        }

        $id = (string) new UuidV7;
        DB::table('kb_chunks')->insert($row + ['id' => $id]);

        return $id;
    }

    /** EC-06/[D10]: unpublish FLIPS retrievable — never deletes. */
    public function retire(string $articleRef): int
    {
        return DB::table('kb_chunks')->where('article_ref', $articleRef)->update(['retrievable' => false, 'updated_at' => DB::raw('clock_timestamp()')]);
    }

    /** @return list<float>|null */
    private function embed(string $text): ?array
    {
        try {
            $result = $this->orchestrator->executeEmbeddings([$text]);

            return $result[0] ?? null;
        } catch (Throwable) {
            return null; // keyword half still serves; the nightly re-embed fills it
        }
    }
}
