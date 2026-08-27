<?php

declare(strict_types=1);

namespace App\Domain\Kb\Categories;

use App\Domain\Ai\Retrieval\KbIndexer;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * Doc 10 FR-01 — the category tree: single hierarchy, max depth 3 (the CHECK is
 * physics; this door computes depth from the parent), per-language names, slug,
 * position (the ordering key), icon, ai_usable (the CATEGORY-level flag that
 * re-derives Doc 09's chunk interim — amendment-3 FLAG 3's reopener), visibility
 * default for children. EC-03: deleting a category with articles is REFUSED with
 * the retrieval impact stated; reassignment is the only path, and moving articles
 * into a non-ai_usable category retires their chunks (EC-02 — the unpublish path).
 * Soft delete (A-43). Every mutation audits.
 */
final class CategoryTree
{
    public const MAX_DEPTH = 3;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly KbIndexer $indexer,
    ) {}

    /** @param array<string, string> $name */
    public function create(array $name, string $slug, ?string $parentId, bool $aiUsable, string $visibilityDefault, string $actorId, int $position = 0, ?string $icon = null): string
    {
        $depth = 1;
        if ($parentId !== null) {
            $parentDepth = DB::table('kb_categories')->where('id', $parentId)->whereNull('deleted_at')->value('depth');
            if ($parentDepth === null) {
                throw new InvalidArgumentException('unknown parent category');
            }
            $depth = (is_numeric($parentDepth) ? (int) $parentDepth : 0) + 1;
            if ($depth > self::MAX_DEPTH) {
                throw new InvalidArgumentException(sprintf('the tree is at most %d deep (FR-01)', self::MAX_DEPTH));
            }
        }
        $id = (string) new UuidV7;
        DB::transaction(function () use ($id, $name, $slug, $parentId, $depth, $aiUsable, $visibilityDefault, $actorId, $position, $icon): void {
            DB::table('kb_categories')->insert([
                'id' => $id, 'tenant_id' => $this->context->tenantId(), 'parent_id' => $parentId, 'depth' => $depth,
                'name' => json_encode($name, JSON_THROW_ON_ERROR), 'slug' => $slug, 'position' => $position, 'icon' => $icon,
                'ai_usable' => $aiUsable, 'visibility_default' => $visibilityDefault,
            ]);
            $this->audit->record('kb.category.created', 'kb_category', $id, AuditActor::Staff, ['slug' => $slug, 'depth' => $depth, 'ai_usable' => $aiUsable], actorId: $actorId);
        });

        return $id;
    }

    /** @param array<string, mixed> $changes  name|slug|position|icon|ai_usable|visibility_default|description */
    public function update(string $id, array $changes, string $actorId): void
    {
        $allowed = ['name', 'slug', 'position', 'icon', 'ai_usable', 'visibility_default', 'description'];
        $row = array_intersect_key($changes, array_flip($allowed));
        if ($row === []) {
            return;
        }
        foreach (['name', 'description'] as $json) {
            if (array_key_exists($json, $row)) {
                $row[$json] = json_encode($row[$json], JSON_THROW_ON_ERROR);
            }
        }
        DB::transaction(function () use ($id, $row, $changes, $actorId): void {
            /** @var object{ai_usable: bool, slug: string}|null $before */
            $before = DB::table('kb_categories')->where('id', $id)->whereNull('deleted_at')->first(['ai_usable', 'slug']);
            if ($before === null) {
                throw new InvalidArgumentException('unknown category');
            }
            DB::table('kb_categories')->where('id', $id)->update($row + ['updated_at' => DB::raw('clock_timestamp()')]);
            $this->audit->record('kb.category.updated', 'kb_category', $id, AuditActor::Staff, ['fields' => array_keys($row), 'old' => ['ai_usable' => (bool) $before->ai_usable], 'new' => ['ai_usable' => (bool) ($changes['ai_usable'] ?? $before->ai_usable)]], actorId: $actorId);
            // FLAG 3's re-derivation (EC-02's category-level edge): flipping a
            // category to non-ai_usable retires every article's chunks — the same
            // atomic path as unpublish; flipping it on marks them index-pending.
            if (array_key_exists('ai_usable', $changes) && (bool) $changes['ai_usable'] !== (bool) $before->ai_usable) {
                /** @var list<string> $articleIds */
                $articleIds = DB::table('kb_articles')->where('category_id', $id)->whereNull('deleted_at')->pluck('id')->all();
                foreach ($articleIds as $articleId) {
                    $this->applyAiUsable($articleId, (bool) $changes['ai_usable']);
                }
            }
        });
    }

    /** The EC-03 dialog fact: how many published PUBLIC articles would leave the chatbot's knowledge if moved to $targetId. */
    public function retrievalImpact(string $categoryId, ?string $targetId): int
    {
        $sourceUsable = (bool) DB::table('kb_categories')->where('id', $categoryId)->value('ai_usable');
        $targetUsable = $targetId === null ? false : (bool) DB::table('kb_categories')->where('id', $targetId)->value('ai_usable');
        if (! $sourceUsable || $targetUsable) {
            return 0;
        }

        return (int) DB::table('kb_articles')->where('category_id', $categoryId)->whereNull('deleted_at')->where('audience', 'public')
            ->whereExists(fn (\Illuminate\Database\Query\Builder $q) => $q->selectRaw('1')->from('kb_article_variants')->whereColumn('kb_article_variants.article_id', 'kb_articles.id')->where('kb_article_variants.status', 'published'))
            ->count();
    }

    /** Move every article of $fromId into $toId (FR-01's reassign-before-delete). */
    public function reassign(string $fromId, string $toId, string $actorId): int
    {
        if ($fromId === $toId) {
            throw new InvalidArgumentException('reassign needs a different target');
        }
        /** @var object{ai_usable: bool}|null $target */
        $target = DB::table('kb_categories')->where('id', $toId)->whereNull('deleted_at')->first(['ai_usable']);
        if ($target === null) {
            throw new InvalidArgumentException('unknown target category');
        }

        return DB::transaction(function () use ($fromId, $toId, $target, $actorId): int {
            $impact = $this->retrievalImpact($fromId, $toId);
            /** @var list<string> $ids */
            $ids = DB::table('kb_articles')->where('category_id', $fromId)->whereNull('deleted_at')->pluck('id')->all();
            $moved = DB::table('kb_articles')->where('category_id', $fromId)->whereNull('deleted_at')->update(['category_id' => $toId, 'updated_at' => DB::raw('clock_timestamp()')]);
            $sourceUsable = (bool) DB::table('kb_categories')->where('id', $fromId)->value('ai_usable');
            if ((bool) $target->ai_usable !== $sourceUsable) {
                foreach ($ids as $articleId) {
                    $this->applyAiUsable($articleId, (bool) $target->ai_usable);
                }
            }
            $this->audit->record('kb.category.reassigned', 'kb_category', $fromId, AuditActor::Staff, ['to' => $toId, 'articles' => $moved, 'retrieval_impact' => $impact], actorId: $actorId);

            return $moved;
        });
    }

    /** EC-03: refused while articles remain — the exception carries the dialog facts. */
    public function delete(string $id, string $actorId): void
    {
        DB::transaction(function () use ($id, $actorId): void {
            $articles = (int) DB::table('kb_articles')->where('category_id', $id)->whereNull('deleted_at')->count();
            if ($articles > 0) {
                throw new CategoryHasArticles($articles, $this->retrievalImpact($id, null));
            }
            $children = (int) DB::table('kb_categories')->where('parent_id', $id)->whereNull('deleted_at')->count();
            if ($children > 0) {
                throw new InvalidArgumentException('a category with child categories is not deleted — move or delete them first (FR-01)');
            }
            $updated = DB::table('kb_categories')->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
            if ($updated === 0) {
                throw new InvalidArgumentException('unknown category');
            }
            $this->audit->record('kb.category.deleted', 'kb_category', $id, AuditActor::Staff, ['soft' => true], actorId: $actorId);
        });
    }

    /** @return list<array<string, mixed>> the tree, ordered by (depth, position), deleted rows absent */
    public function tree(): array
    {
        /** @var list<object{id: string, parent_id: string|null, depth: int|string, name: string, slug: string, position: int|string, icon: string|null, ai_usable: bool, visibility_default: string}> $rows */
        $rows = DB::table('kb_categories')->whereNull('deleted_at')->orderBy('depth')->orderBy('position')->orderBy('slug')
            ->get(['id', 'parent_id', 'depth', 'name', 'slug', 'position', 'icon', 'ai_usable', 'visibility_default'])->all();
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => $row->id, 'parent_id' => $row->parent_id, 'depth' => (int) $row->depth, 'name' => json_decode($row->name, true), 'slug' => $row->slug, 'position' => (int) $row->position, 'icon' => $row->icon, 'ai_usable' => (bool) $row->ai_usable, 'visibility_default' => $row->visibility_default];
        }

        return $out;
    }

    /** The FLAG-3 re-derivation, shared with ArticleWriter::move — category flag → chunk state. */
    public function applyAiUsable(string $articleId, bool $aiUsable): void
    {
        if (! $aiUsable) {
            $this->indexer->retire($articleId); // EC-02: the same atomic path as unpublish
            DB::table('kb_article_variants')->where('article_id', $articleId)->where('index_status', 'current')->update(['index_status' => 'none', 'updated_at' => DB::raw('clock_timestamp()')]);

            return;
        }
        // Back into the chatbot's scope: published variants re-index through the pipeline (step 6) — marked pending, never served stale.
        DB::table('kb_article_variants')->where('article_id', $articleId)->where('status', 'published')->update(['index_status' => 'pending', 'updated_at' => DB::raw('clock_timestamp()')]);
    }
}
