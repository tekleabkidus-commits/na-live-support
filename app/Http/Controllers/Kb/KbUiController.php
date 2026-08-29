<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

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
 */
final class KbUiController extends Controller
{
    public function __construct(private readonly CapabilityMapProvider $verdicts, private readonly PublishSanitizer $sanitizer) {}

    public function manager(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $seesInternal = $this->verdicts->mapFor($user)->allows('kb.view_internal');
        /** @var list<object{id: string, parent_id: string|null, name: string, slug: string, position: int, ai_usable: bool}> $categories */
        $categories = DB::table('kb_categories')->whereNull('deleted_at')->orderBy('position')->orderBy('slug')->get(['id', 'parent_id', 'name', 'slug', 'position', 'ai_usable'])->all();
        $articles = DB::table('kb_articles as a')->whereNull('a.deleted_at');
        if (! $seesInternal) {
            $articles->where('a.audience', 'public');
        }
        /** @var list<object{id: string, category_id: string, audience: string, source_language: string}> $articleRows */
        $articleRows = $articles->orderBy('a.created_at')->get(['a.id', 'a.category_id', 'a.audience', 'a.source_language'])->all();
        /** @var list<object{id: string, article_id: string, language: string, status: string, translation_state: string, translation_source: string, title: string, index_status: string}> $variantRows */
        $variantRows = DB::table('kb_article_variants')->whereIn('article_id', array_column($articleRows, 'id'))
            ->orderBy('language')->get(['id', 'article_id', 'language', 'status', 'translation_state', 'translation_source', 'title', 'index_status'])->all();
        $variantsByArticle = [];
        foreach ($variantRows as $v) {
            $variantsByArticle[$v->article_id][] = (array) $v;
        }

        return Inertia::render('Admin/Kb/Manager', [
            't' => __('kb.manager'),
            'categories' => array_map(fn (object $c): array => (array) $c + ['name' => json_decode($c->name, true)], $categories),
            'articles' => array_map(fn (object $a): array => (array) $a + ['variants' => $variantsByArticle[$a->id] ?? []], $articleRows),
            'can' => [
                'create' => $this->verdicts->mapFor($user)->allows('kb.articles.create'),
                'manage_categories' => $this->verdicts->mapFor($user)->allows('kb.categories.manage'),
                'view_internal' => $seesInternal,
            ],
        ]);
    }

    public function editor(Request $request, string $variantId): Response
    {
        /** @var User $user */
        $user = $request->user();
        /** @var object{id: string, article_id: string, language: string, status: string, translation_state: string, translation_source: string, title: string, summary: string|null, slug: string, working_copy: string, current_version_id: string|null, index_status: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)
            ->first(['id', 'article_id', 'language', 'status', 'translation_state', 'translation_source', 'title', 'summary', 'slug', 'working_copy', 'current_version_id', 'index_status']);
        abort_if($variant === null, 404);
        /** @var object{id: string, audience: string, category_id: string}|null $article */
        $article = DB::table('kb_articles')->where('id', $variant->article_id)->whereNull('deleted_at')->first(['id', 'audience', 'category_id']);
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
        /** @var list<object{id: string, version_id: string|null, block_anchor: string|null, body: string, author_id: string, resolved_at: string|null, created_at: string}> $comments */
        $comments = DB::table('kb_review_comments')->where('variant_id', $variantId)->orderBy('created_at')->get(['id', 'version_id', 'block_anchor', 'body', 'author_id', 'resolved_at', 'created_at'])->all();

        return Inertia::render('Admin/Kb/Editor', [
            't' => __('kb.manager'),
            // array union keeps the LEFT key — the decoded copy MUST win over the row's raw JSON string
            // (the step-9 honest trail's + trap; caught by the §14 browser leg).
            'variant' => ['working_copy' => $workingCopy] + (array) $variant,
            'article' => (array) $article,
            'siblings' => array_map(fn (object $s): array => (array) $s, $siblings),
            'flags' => $flags,
            'comments' => array_map(fn (object $c): array => (array) $c, $comments),
            'can' => [
                'publish' => $this->verdicts->mapFor($user)->allows('kb.articles.publish'),
                'stats' => $this->verdicts->mapFor($user)->allows('kb.feedback.view'),
            ],
        ]);
    }

    public function history(Request $request, string $variantId): Response
    {
        /** @var object{id: string, article_id: string, language: string, title: string, current_version_id: string|null}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)->first(['id', 'article_id', 'language', 'title', 'current_version_id']);
        abort_if($variant === null, 404);
        /** @var list<object{id: string, version: int, author_id: string, note: string|null, content_hash: string, published_at: string}> $versions */
        $versions = DB::table('kb_article_versions')->where('variant_id', $variantId)->orderByDesc('version')->limit(100)->get(['id', 'version', 'author_id', 'note', 'content_hash', 'published_at'])->all();

        return Inertia::render('Admin/Kb/History', [
            't' => __('kb.manager'),
            'variant' => (array) $variant,
            'versions' => array_map(fn (object $v): array => (array) $v, $versions),
        ]);
    }
}
