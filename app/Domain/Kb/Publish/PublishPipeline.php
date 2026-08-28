<?php

declare(strict_types=1);

namespace App\Domain\Kb\Publish;

use App\Domain\Ai\Retrieval\KbIndexer;
use App\Domain\Kb\Chunking\DeterministicChunker;
use App\Support\Cache\TenantCache;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Doc 10 FR-13 / T-01 / T-02 / T-04 / NFR-01 / NFR-06 / EC-04 / FS-01 / AC-03 — THE
 * PUBLISH PIPELINE (retrieval side). Humans went live at Transitions::publish; this
 * runs from the durable consumer AFTER that commit:
 *
 *   chunk (deterministic) → sanitize (index-time strip inside the one door) → embed
 *   ONLY changed chunks (an unchanged chunk re-uses the serving generation's vector —
 *   the §12 cost rule) → write generation N+1 NON-retrievable beside N → verify every
 *   changed chunk got a vector (FS-01: embeddings down ⇒ NO swap, index_status stays
 *   `pending`, the job's retry/re-drive drains later, the chatbot keeps serving N) →
 *   the ONE-statement swap (NFR-06: never a mix) → purge caches → kb.retrieval.updated.
 *
 * Every write to kb_chunks goes through KbIndexer (the one door; KbOneIndexerDoorTest).
 * Idempotent on the event: a version already serving is a no-op; a half-written N+1
 * (a stage kill — AC-03) is simply re-written by the arbiter and then swapped.
 */
final class PublishPipeline
{
    /** Test seam for the AC-03 stage-kill chaos proof: throw AFTER this stage completes. */
    public static ?string $killAfterStage = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly KbIndexer $indexer,
        private readonly DeterministicChunker $chunker,
        private readonly EventPublisher $events,
        private readonly TenantCache $cache,
    ) {}

    /** @return array{status: string, generation: int, chunks: int, embedded: int, reused: int} */
    public function index(string $variantId, string $versionId): array
    {
        /** @var object{id: string, article_id: string, language: string, status: string, current_version_id: string|null, retrieval_generation: int|string, index_status: string, title: string, summary: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)->first(['id', 'article_id', 'language', 'status', 'current_version_id', 'retrieval_generation', 'index_status', 'title', 'summary']);
        if ($variant === null) {
            throw new InvalidArgumentException('unknown variant');
        }
        if ($variant->status !== 'published' || $variant->current_version_id !== $versionId) {
            return ['status' => 'superseded', 'generation' => (int) $variant->retrieval_generation, 'chunks' => 0, 'embedded' => 0, 'reused' => 0]; // a later publish/unpublish won the race — nothing to index for this event
        }
        /** @var object{content: string, title: string, summary: string, audience_at_publish: string}|null $version */
        $version = DB::table('kb_article_versions')->where('id', $versionId)->first(['content', 'title', 'summary', 'audience_at_publish']);
        /** @var object{audience: string, category_id: string}|null $article */
        $article = DB::table('kb_articles')->where('id', $variant->article_id)->first(['audience', 'category_id']);
        if ($version === null || $article === null) {
            throw new InvalidArgumentException('unknown version or article');
        }
        $aiUsable = (bool) DB::table('kb_categories')->where('id', $article->category_id)->value('ai_usable');
        $current = (int) $variant->retrieval_generation;

        // Idempotency (C-09 in substance): this version already serves → no-op.
        if ($variant->index_status === 'current' && $current > 0 && DB::table('kb_chunks')->where('variant_id', $variantId)->where('version_id', $versionId)->where('retrievable', true)->exists()) {
            return ['status' => 'current', 'generation' => $current, 'chunks' => 0, 'embedded' => 0, 'reused' => 0];
        }

        $decoded = json_decode($version->content, true);
        /** @var array<string, mixed> $content */
        $content = is_array($decoded) ? $decoded : [];
        $chunks = $this->chunker->chunk($version->title, $version->summary, $content);
        $this->kill('chunk');

        // The serving generation's vectors by chunk HASH — re-chunk the serving version
        // (T-02 determinism makes that exact) and pair by hash, so an unchanged chunk
        // re-uses its vector. Read-only on kb_chunks: the one door stays KbIndexer.
        /** @var array<string, string> $reusable hash → vector literal */
        $reusable = [];
        if ($current > 0) {
            /** @var object{version_id: string}|null $servingRow */
            $servingRow = DB::table('kb_chunks')->where('variant_id', $variantId)->where('retrieval_generation', $current)->first(['version_id']);
            /** @var object{content: string, title: string, summary: string}|null $servingVersion */
            $servingVersion = $servingRow === null ? null : DB::table('kb_article_versions')->where('id', $servingRow->version_id)->first(['content', 'title', 'summary']);
            if ($servingVersion !== null) {
                $servingDecoded = json_decode($servingVersion->content, true);
                /** @var array<string, mixed> $servingContent */
                $servingContent = is_array($servingDecoded) ? $servingDecoded : [];
                $hashesByIndex = array_column($this->chunker->chunk($servingVersion->title, $servingVersion->summary, $servingContent), 'hash', 'index');
                /** @var list<object{chunk_index: int|string, embedding: string}> $servingChunks */
                $servingChunks = DB::table('kb_chunks')->where('variant_id', $variantId)->where('retrieval_generation', $current)->whereNotNull('embedding')->get(['chunk_index', DB::raw('embedding::text AS embedding')])->all();
                foreach ($servingChunks as $row) {
                    $hash = $hashesByIndex[(int) $row->chunk_index] ?? null;
                    if (is_string($hash)) {
                        $reusable[$hash] = $row->embedding;
                    }
                }
            }
        }

        $next = $current + 1;
        $embedded = 0;
        $reused = 0;
        foreach ($chunks as $chunk) {
            $vector = null;
            if (isset($reusable[$chunk['hash']])) {
                $vector = $this->vectorFromLiteral($reusable[$chunk['hash']]);
                $reused++;
            } else {
                $embedded++;
            }
            $this->indexer->index(
                $variant->article_id, $chunk['index'], $variant->language, $chunk['text'], $article->audience, $aiUsable,
                fixtureVector: $vector, variantId: $variantId, versionId: $versionId, generation: $next, headingPath: $chunk['heading_path'],
                retrievable: false,
            );
        }
        $this->kill('embed');

        // FS-01: a changed chunk without a vector means the embeddings provider is down —
        // NO swap; the previous generation keeps serving; the consumer retries.
        $missing = (int) DB::table('kb_chunks')->where('variant_id', $variantId)->where('retrieval_generation', $next)->whereNull('embedding')->count();
        if ($missing > 0) {
            DB::table('kb_article_variants')->where('id', $variantId)->update(['index_status' => 'pending', 'updated_at' => DB::raw('clock_timestamp()')]);
            throw new RuntimeException(sprintf('embeddings pending for %d chunk(s) — generation %d not swapped (FS-01); retry drains on recovery', $missing, $next));
        }

        DB::transaction(function () use ($variantId, $versionId, $variant, $next): void {
            $this->indexer->swap($variantId, $next);
            DB::table('kb_article_variants')->where('id', $variantId)->update(['retrieval_generation' => $next, 'index_status' => 'current', 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->events->publish('kb.retrieval.updated', [
                'tenant_id' => $this->context->tenantId(), 'article_id' => $variant->article_id, 'variant_id' => $variantId,
                'version_id' => $versionId, 'retrieval_generation' => $next, 'status' => 'current',
            ]);
        });
        $this->kill('swap');
        $this->purge($variant->article_id);

        return ['status' => 'current', 'generation' => $next, 'chunks' => count($chunks), 'embedded' => $embedded, 'reused' => $reused];
    }

    /** T-05: the category tree + article render caches purge on publish (step 11 fills them; the keys are named here once). */
    public function purge(string $articleId): void
    {
        $this->cache->forget('kb:tree');
        $this->cache->forget('kb:article:'.$articleId);
    }

    private function kill(string $stage): void
    {
        if (self::$killAfterStage === $stage) {
            self::$killAfterStage = null;
            throw new RuntimeException('stage killed after '.$stage.' (AC-03 chaos)');
        }
    }

    /** @return list<float>|null */
    private function vectorFromLiteral(string $literal): ?array
    {
        $parts = explode(',', trim($literal, '[]'));
        if (count($parts) !== 1024) {
            return null;
        }

        return array_map(static fn (string $p): float => (float) $p, $parts);
    }
}
