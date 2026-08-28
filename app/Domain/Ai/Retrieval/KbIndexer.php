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
 * where audience/ai_usable/language land. Upsert on the (tenant, audience,
 * variant, generation, chunk_index) arbiter — the only non-PK unique (the
 * arbiter law; SCHEMA AMENDMENT 4, S10 step 2b: variant/version/generation/
 * heading_path/sanitizer_flags ride the row, `swap()` is the ONE-statement
 * generation door, retirement still FLIPS). Doc 09-owned; edited under the
 * S10 2b countersign — the ONLY writer of kb_chunks (KbOneIndexerDoorTest).
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
     * @param  string|null  $variantId  Doc 10's variant (defaults to the article ref for legacy/fixture callers)
     * @param  string|null  $versionId  Doc 10's version (FR-14's citation pin; defaults likewise)
     * @param  int  $generation  T-01's retrieval generation (rows land with $retrievable; swap() flips)
     * @param  list<array<string, mixed>>  $sanitizerFlags  T-03 flags surfaced to the publisher (also on the version)
     * @return string the chunk id
     */
    public function index(
        string $articleRef,
        int $chunkIndex,
        string $language,
        string $rawContent,
        string $audience = 'public',
        bool $aiUsable = true,
        ?array $fixtureVector = null,
        ?string $variantId = null,
        ?string $versionId = null,
        int $generation = 1,
        string $headingPath = '',
        array $sanitizerFlags = [],
        bool $retrievable = true,
    ): string {
        $variantId ??= $articleRef;
        $versionId ??= $articleRef;
        $stripped = $this->stripper->strip($rawContent);
        $vector = $fixtureVector ?? $this->embed($stripped['content']);

        $row = [
            'tenant_id' => $this->context->tenantId(),
            'article_ref' => $articleRef,
            'variant_id' => $variantId,
            'version_id' => $versionId,
            'retrieval_generation' => $generation,
            'chunk_index' => $chunkIndex,
            'language' => $language,
            'audience' => $audience,
            'ai_usable' => $aiUsable,
            'heading_path' => $headingPath,
            'content' => $stripped['content'],
            'provenance' => json_encode(['article_ref' => $articleRef, 'variant_id' => $variantId, 'version_id' => $versionId, 'stripped_patterns' => $stripped['stripped'], 'source' => 'KbIndexer'], JSON_THROW_ON_ERROR),
            'sanitizer_flags' => json_encode($sanitizerFlags, JSON_THROW_ON_ERROR),
            'embedding' => $vector === null ? null : '['.implode(',', $vector).']',
            'retrievable' => $retrievable,
            'updated_at' => DB::raw('clock_timestamp()'),
        ];

        // The arbiter (SCHEMA AMENDMENT 4): select-then-write, never ON CONFLICT.
        /** @var object{id: string, audience: string}|null $existing */
        $existing = DB::table('kb_chunks')
            ->where('variant_id', $variantId)->where('retrieval_generation', $generation)->where('chunk_index', $chunkIndex)
            ->first(['id', 'audience']);
        if ($existing !== null && $existing->audience === $audience) {
            // Re-index rewrites in place through the app's UPDATE grant ONLY (SCHEMA AMENDMENT 4):
            // identity columns never move — the grant refuses them, so they are not named.
            DB::table('kb_chunks')->where('id', $existing->id)->update(array_intersect_key($row, array_flip([
                'content', 'provenance', 'embedding', 'language', 'ai_usable', 'retrievable', 'heading_path', 'sanitizer_flags', 'version_id', 'retrieval_generation', 'updated_at',
            ])));

            return $existing->id;
        }
        if ($existing !== null) {
            // An audience change is a re-index into the OTHER partition (EC-11):
            // the old row retires in place, a new row lands — never a row move.
            DB::table('kb_chunks')->where('id', $existing->id)->update(['retrievable' => false, 'updated_at' => DB::raw('clock_timestamp()')]);
        }

        $id = (string) new UuidV7;
        DB::table('kb_chunks')->insert($row + ['id' => $id]);

        return $id;
    }

    /**
     * T-01/NFR-06/AC-03: the ONE-statement generation swap — after it, exactly the
     * rows of $generation serve for the variant; no reader ever sees a mix.
     */
    public function swap(string $variantId, int $generation): int
    {
        return DB::update(
            'UPDATE kb_chunks SET retrievable = (retrieval_generation = ?), updated_at = clock_timestamp() WHERE variant_id = ?',
            [$generation, $variantId],
        );
    }

    /** EC-06/[D10]: unpublish FLIPS retrievable — never deletes (every variant of the article). */
    public function retire(string $articleRef): int
    {
        return DB::table('kb_chunks')->where('article_ref', $articleRef)->update(['retrievable' => false, 'updated_at' => DB::raw('clock_timestamp()')]);
    }

    /** The same flip for ONE variant (EC-02's move, an unpublish of one language). */
    public function retireVariant(string $variantId): int
    {
        return DB::table('kb_chunks')->where('variant_id', $variantId)->update(['retrievable' => false, 'updated_at' => DB::raw('clock_timestamp()')]);
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
