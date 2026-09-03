<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Categories\CategoryTree;
use App\Domain\Kb\Editor\Locks;
use App\Domain\Kb\Sanitizer\PublishSanitizer;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Doc 10 §7/§8 staff surfaces (S10 step 12, Option A → [B9]): functional-first Inertia
 * renders from design-system tokens — the manager three-pane, the editor (language tabs,
 * blocks, sanitizer flags pre-publish, publish dialog facts, AI drawer, INTERNAL banner,
 * review thread), and version history. Every MUTATION rides the step-3…11 doors already
 * proven by the suites — these pages hold no business logic. INTERNAL articles are ABSENT
 * from every list for a viewer without kb.view_internal (FR-10; absence, not a lock icon).
 *
 * SESSION-F1 [B9] (frozen B9-S01…S04): the same pages carry the drawn facts as ADDITIVE,
 * read-only props — the category tree with counts and the FR-01 fields, per-article author /
 * updated / usage (FR-12's own events), the active languages, per-version authors, reviewers
 * and AI-citation counts, and the sanitizer record. No door changed; nothing here mutates.
 */
final class KbUiController extends Controller
{
    public function __construct(
        private readonly CapabilityMapProvider $verdicts,
        private readonly PublishSanitizer $sanitizer,
        private readonly CategoryTree $tree,
        private readonly Locks $locks,
    ) {}

    public function manager(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $seesInternal = $this->verdicts->mapFor($user)->allows('kb.view_internal');
        $categories = $this->tree->tree();
        $articles = DB::table('kb_articles as a')->whereNull('a.deleted_at');
        if (! $seesInternal) {
            $articles->where('a.audience', 'public');
        }
        /** @var list<object{id: string, category_id: string, audience: string, source_language: string, seeded: bool, created_at: string, author_name: string|null}> $articleRows */
        $articleRows = $articles->leftJoin('users as u', 'u.id', '=', 'a.created_by')->orderBy('a.created_at')
            ->get(['a.id', 'a.category_id', 'a.audience', 'a.source_language', 'a.seeded', DB::raw('a.created_at::text AS created_at'), 'u.name as author_name'])->all();
        $articleIds = array_column($articleRows, 'id');
        /** @var list<object{id: string, article_id: string, language: string, status: string, translation_state: string, translation_source: string, title: string, slug: string, summary: string, index_status: string, updated_at: string, published_at: string|null}> $variantRows */
        $variantRows = DB::table('kb_article_variants')->whereIn('article_id', $articleIds)
            ->orderBy('language')->get(['id', 'article_id', 'language', 'status', 'translation_state', 'translation_source', 'title', 'slug', 'summary', 'index_status', DB::raw('updated_at::text AS updated_at'), DB::raw('published_at::text AS published_at')])->all();
        $variantsByArticle = [];
        foreach ($variantRows as $v) {
            $variantsByArticle[$v->article_id][] = (array) $v;
        }
        $usage = $this->usageByArticle();

        // Per-category facts (S04 §D): articles · published · in the chatbot — the same
        // visibility rule as the list (an internal article is absent from every count too).
        $counts = [];
        $published = [];
        $inChatbot = [];
        $aiUsable = [];
        foreach ($categories as $category) {
            $aiUsable[$category['id']] = (bool) $category['ai_usable'];
        }
        foreach ($articleRows as $article) {
            $counts[$article->category_id] = ($counts[$article->category_id] ?? 0) + 1;
            $hasPublished = false;
            foreach ($variantsByArticle[$article->id] ?? [] as $variant) {
                if (($variant['status'] ?? '') === 'published') {
                    $hasPublished = true;
                }
            }
            if ($hasPublished) {
                $published[$article->category_id] = ($published[$article->category_id] ?? 0) + 1;
                if ($article->audience === 'public' && ($aiUsable[$article->category_id] ?? false)) {
                    $inChatbot[$article->category_id] = ($inChatbot[$article->category_id] ?? 0) + 1;
                }
            }
        }

        return Inertia::render('Admin/Kb/Manager', [
            't' => __('kb.manager'),
            'categories' => array_map(fn (array $c): array => $c + [
                'count' => $counts[$c['id']] ?? 0,
                'published_count' => $published[$c['id']] ?? 0,
                'chatbot_count' => $inChatbot[$c['id']] ?? 0,
                'description' => $this->categoryDescription($c['id']),
            ], $categories),
            'articles' => array_map(fn (object $a): array => (array) $a + [
                'variants' => $variantsByArticle[$a->id] ?? [],
                'views' => $usage[$a->id]['views'] ?? 0,
                'citations' => $usage[$a->id]['citations'] ?? 0,
            ], $articleRows),
            'languages' => $this->activeLanguages(),
            'can' => [
                'create' => $this->verdicts->mapFor($user)->allows('kb.articles.create'),
                'manage_categories' => $this->verdicts->mapFor($user)->allows('kb.categories.manage'),
                'view_internal' => $seesInternal,
                'edit' => $this->verdicts->mapFor($user)->allows('kb.articles.edit'),
            ],
        ]);
    }

    public function editor(Request $request, string $variantId): Response
    {
        /** @var User $user */
        $user = $request->user();
        /** @var object{id: string, article_id: string, language: string, status: string, translation_state: string, translation_source: string, title: string, summary: string|null, slug: string, working_copy: string, current_version_id: string|null, index_status: string, working_copy_saved_at: string|null}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)
            ->first(['id', 'article_id', 'language', 'status', 'translation_state', 'translation_source', 'title', 'summary', 'slug', 'working_copy', 'current_version_id', 'index_status', DB::raw('working_copy_saved_at::text AS working_copy_saved_at')]);
        abort_if($variant === null, 404);
        /** @var object{id: string, audience: string, category_id: string, source_language: string}|null $article */
        $article = DB::table('kb_articles')->where('id', $variant->article_id)->whereNull('deleted_at')->first(['id', 'audience', 'category_id', 'source_language']);
        abort_if($article === null, 404);
        if ($article->audience === 'internal' && ! $this->verdicts->mapFor($user)->allows('kb.view_internal')) {
            abort(404); // C-10 posture: absence, not a lock icon
        }
        /** @var list<object{id: string, language: string, status: string, translation_state: string}> $siblings */
        $siblings = DB::table('kb_article_variants')->where('article_id', $variant->article_id)->orderBy('language')->get(['id', 'language', 'status', 'translation_state'])->all();
        $decoded = json_decode($variant->working_copy, true);
        /** @var array<string, mixed> $workingCopy */
        $workingCopy = is_array($decoded) ? $decoded : [];
        // §8: flags surface to the publisher BEFORE publish — the same scan the publish door runs.
        $flags = $this->sanitizer->scan($workingCopy, $variant->language);
        /** @var list<object{id: string, version_id: string|null, block_anchor: string|null, body: string, author_id: string, resolved_at: string|null, created_at: string, author_name: string|null}> $comments */
        $comments = DB::table('kb_review_comments as c')->leftJoin('users as u', 'u.id', '=', 'c.author_id')->where('c.variant_id', $variantId)->orderBy('c.created_at')
            ->get(['c.id', 'c.version_id', 'c.block_anchor', 'c.body', 'c.author_id', DB::raw('c.resolved_at::text AS resolved_at'), DB::raw('c.created_at::text AS created_at'), 'u.name as author_name'])->all();
        /** @var object{id: string, name: string, ai_usable: bool}|null $category */
        $category = DB::table('kb_categories')->where('id', $article->category_id)->first(['id', 'name', 'ai_usable']);
        /** @var object{version: int|string, note: string|null, sanitizer_override: string|null, published_at: string, author_name: string|null}|null $current */
        $current = $variant->current_version_id === null ? null
            : DB::table('kb_article_versions as v')->leftJoin('users as u', 'u.id', '=', 'v.author_id')->where('v.id', $variant->current_version_id)
                ->first(['v.version', 'v.note', 'v.sanitizer_override', DB::raw('v.published_at::text AS published_at'), 'u.name as author_name']);

        // B9-S02 B03: who holds the edit lock right now (the acquire door decides; this is the name for the banner).
        $holderId = $this->locks->holder($variantId);
        $holderName = $holderId === null ? null : DB::table('users')->where('id', $holderId)->value('name');

        return Inertia::render('Admin/Kb/Editor', [
            't' => __('kb.manager'),
            // array union keeps the LEFT key — the decoded copy MUST win over the row's raw JSON string
            // (the step-9 honest trail's + trap; caught by the §14 browser leg).
            'variant' => ['working_copy' => $workingCopy] + (array) $variant,
            'article' => (array) $article + [
                'category_name' => $category === null ? null : $this->localName($category->name),
                'category_ai_usable' => $category !== null && (bool) $category->ai_usable,
            ],
            'siblings' => array_map(fn (object $s): array => (array) $s, $siblings),
            'flags' => $flags,
            'comments' => array_map(fn (object $c): array => (array) $c, $comments),
            'current_version' => $current === null ? null : [
                'version' => (int) $current->version, 'note' => $current->note, 'published_at' => $current->published_at, 'author_name' => $current->author_name,
                'override_kept' => $current->sanitizer_override !== null,
            ],
            'languages' => $this->activeLanguages(),
            'lock' => ['holder_id' => $holderId, 'holder_name' => is_string($holderName) ? $holderName : null, 'mine' => $holderId !== null && $holderId === $user->identifier()],
            'can' => [
                'publish' => $this->verdicts->mapFor($user)->allows('kb.articles.publish'),
                'stats' => $this->verdicts->mapFor($user)->allows('kb.feedback.view'),
            ],
        ]);
    }

    public function history(Request $request, string $variantId): Response
    {
        /** @var object{id: string, article_id: string, language: string, title: string, status: string, current_version_id: string|null, translation_source: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)->first(['id', 'article_id', 'language', 'title', 'status', 'current_version_id', 'translation_source']);
        abort_if($variant === null, 404);
        /** @var object{audience: string, source_language: string, seeded: bool, category_id: string}|null $article */
        $article = DB::table('kb_articles')->where('id', $variant->article_id)->first(['audience', 'source_language', 'seeded', 'category_id']);
        /** @var object{name: string}|null $category */
        $category = $article === null ? null : DB::table('kb_categories')->where('id', $article->category_id)->first(['name']);
        /** @var list<object{id: string, version: int, author_id: string, note: string|null, content_hash: string, published_at: string, sanitizer_override: string|null, sanitizer_flags: string, author_name: string|null}> $versions */
        $versions = DB::table('kb_article_versions as v')->leftJoin('users as u', 'u.id', '=', 'v.author_id')->where('v.variant_id', $variantId)->orderByDesc('v.version')->limit(100)
            ->get(['v.id', 'v.version', 'v.author_id', 'v.note', 'v.content_hash', DB::raw('v.published_at::text AS published_at'), 'v.sanitizer_override', 'v.sanitizer_flags', 'u.name as author_name'])->all();
        $versionIds = array_column($versions, 'id');
        // Reviewers = the people whose review comments were anchored to that version (FR-07's thread).
        $reviewers = [];
        /** @var list<object{version_id: string, name: string|null}> $commentRows */
        $commentRows = $versionIds === [] ? [] : DB::table('kb_review_comments as c')->leftJoin('users as u', 'u.id', '=', 'c.author_id')->whereIn('c.version_id', $versionIds)->get(['c.version_id', 'u.name'])->all();
        foreach ($commentRows as $row) {
            if (is_string($row->name) && ! in_array($row->name, $reviewers[$row->version_id] ?? [], true)) {
                $reviewers[$row->version_id][] = $row->name;
            }
        }
        // AI citations per version (FR-12's kb.article.cited_by_ai carries the version id).
        $cited = [];
        /** @var list<object{version_id: string|null, n: int|string}> $citedRows */
        $citedRows = $versionIds === [] ? [] : DB::table('events')->where('name', 'kb.article.cited_by_ai')->where('payload->article_id', $variant->article_id)
            ->select([DB::raw("payload->>'version_id' AS version_id"), DB::raw('count(*) AS n')])->groupBy(DB::raw("payload->>'version_id'"))->get()->all();
        foreach ($citedRows as $row) {
            if (is_string($row->version_id)) {
                $cited[$row->version_id] = (int) $row->n;
            }
        }

        return Inertia::render('Admin/Kb/History', [
            't' => __('kb.manager'),
            'variant' => (array) $variant,
            'article' => $article === null ? null : (array) $article + ['category_name' => $category === null ? null : $this->localName($category->name)],
            'versions' => array_map(fn (object $v): array => [
                'id' => $v->id, 'version' => (int) $v->version, 'author_id' => $v->author_id, 'author_name' => $v->author_name, 'note' => $v->note,
                'content_hash' => $v->content_hash, 'published_at' => $v->published_at,
                'override_kept' => $v->sanitizer_override !== null,
                'flag_count' => is_array($f = json_decode($v->sanitizer_flags, true)) ? count($f) : 0,
                'reviewers' => $reviewers[$v->id] ?? [],
                'citations' => $cited[$v->id] ?? 0,
                'seeded' => $article !== null && (bool) $article->seeded && (int) $v->version === 1,
                'ai_translated' => $variant->translation_source === 'ai' && (int) $v->version === 1,
            ], $versions),
        ]);
    }

    /**
     * FR-12's own events, grouped once for the list: views (30 d) and AI citations (all time).
     *
     * @return array<string, array{views?: int, citations?: int}>
     */
    private function usageByArticle(): array
    {
        $out = [];
        /** @var list<object{article_id: string|null, name: string, n: int|string}> $rows */
        $rows = DB::table('events')->whereIn('name', ['kb.article.viewed', 'kb.article.cited_by_ai'])
            ->where(function (\Illuminate\Database\Query\Builder $q): void {
                $q->where('name', 'kb.article.cited_by_ai')->orWhereRaw("occurred_at >= clock_timestamp() - interval '30 days'");
            })
            ->select([DB::raw("payload->>'article_id' AS article_id"), 'name', DB::raw('count(*) AS n')])
            ->groupBy(DB::raw("payload->>'article_id'"), 'name')->get()->all();
        foreach ($rows as $row) {
            if (! is_string($row->article_id)) {
                continue;
            }
            $out[$row->article_id][$row->name === 'kb.article.viewed' ? 'views' : 'citations'] = (int) $row->n;
        }

        return $out;
    }

    /** @return list<string> the platform's active languages (Doc 01 FR-10 `ref_languages`, not deprecated) */
    private function activeLanguages(): array
    {
        /** @var list<string> $codes */
        $codes = DB::table('ref_languages')->whereNull('deprecated_at')->orderBy('code')->pluck('code')->all();

        return $codes;
    }

    /** @return array<string, string> */
    private function categoryDescription(string $categoryId): array
    {
        $raw = DB::table('kb_categories')->where('id', $categoryId)->value('description');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $out = [];
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $out[$k] = $v;
                }
            }
        }

        return $out;
    }

    private function localName(string $json): string
    {
        $name = json_decode($json, true);
        if (! is_array($name)) {
            return $json;
        }
        $pick = $name[app()->getLocale()] ?? $name['en'] ?? reset($name);

        return is_string($pick) ? $pick : '';
    }
}
