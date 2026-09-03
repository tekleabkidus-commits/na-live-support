<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Tombstones\SlugResolver;
use App\Domain\Kb\Tombstones\VersionedCitationResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Doc 10 FR-16 / T-06 / SS-04 — the resolve doors (S10 step 7; JSON): a version-pinned
 * citation resolves in ONE lookup (never a 404 — Unknown is a tombstone without a link);
 * a (language, slug) URL resolves current / redirect (slug history) / retired. INTERNAL
 * articles are ABSENT for a reader without kb.view_internal (C-10: absence, not refusal).
 */
final class KbResolveController extends Controller
{
    public function __construct(private readonly VersionedCitationResolver $citations, private readonly SlugResolver $slugs) {}

    public function citation(Request $request, string $articleId, string $versionId): JsonResponse|\Inertia\Response
    {
        $facts = $this->citations->citation($articleId, $versionId)->toArray();
        if (! $this->mayRead($request, $articleId)) {
            $facts = ['state' => 'unknown', 'article_id' => $articleId, 'version_id' => $versionId, 'shown' => null, 'current_url' => null, 'current_version_id' => null, 'tombstone_key' => 'kb.tombstone.unknown'];
        }

        $key = $facts['tombstone_key'];
        $payload = $facts + ['tombstone' => __(is_string($key) ? $key : 'kb.tombstone.unknown')];

        // S10 step 12 ([B9]; inherited row 5's per-door interim): a browser landing on a
        // citation link gets the Tombstone page — the labeled-degrade render (mandate §5);
        // fetch/JSON callers (the chatbot render tests, Copilot) keep the JSON contract.
        if (! $request->expectsJson()) {
            return \Inertia\Inertia::render('Kb/Tombstone', $payload + ['t' => __('kb.manager'), 'snapshot' => $this->snapshot($facts, $articleId, $versionId)]);
        }

        return new JsonResponse($payload);
    }

    public function slug(Request $request, string $language, string $slug): JsonResponse
    {
        $resolution = $this->slugs->resolve($language, $slug);
        if ($resolution['article_id'] !== null && ! $this->mayRead($request, $resolution['article_id'])) {
            $resolution = ['state' => 'unknown', 'article_id' => null, 'variant_id' => null, 'redirect_to' => null, 'tombstone_key' => 'kb.tombstone.unknown'];
        }

        return new JsonResponse($resolution + ['tombstone' => $resolution['tombstone_key'] === null ? null : __($resolution['tombstone_key'])], $resolution['state'] === 'redirect' ? 301 : 200);
    }

    private function mayRead(Request $request, string $articleId): bool
    {
        /** @var User $user */
        $user = $request->user();
        $audience = DB::table('kb_articles')->where('id', $articleId)->value('audience');

        return $audience !== 'internal' || app(\App\Support\Rbac\CapabilityMapProvider::class)->verdictFor($user, 'kb.view_internal')->allowed;
    }

    /**
     * SESSION-F1 [B9] (frozen B9-S03 §C, the staff citation landing): the version-pinned
     * snapshot exactly as the customer read it (FR-14, SS-04) — title, blocks, author, the
     * current version's number and what changed since (the same BlockDiff the history uses).
     * Read-only; absent (null) when the reader may not see the article or nothing is known.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>|null
     */
    private function snapshot(array $facts, string $articleId, string $versionId): ?array
    {
        if (($facts['state'] ?? 'unknown') === 'unknown' || ! is_array($facts['shown'] ?? null)) {
            return null;
        }
        /** @var object{id: string, variant_id: string, version: int|string, language: string, title: string, content: string, audience_at_publish: string, published_at: string, author_name: string|null}|null $version */
        $version = DB::table('kb_article_versions as v')->leftJoin('users as u', 'u.id', '=', 'v.author_id')->where('v.id', $versionId)->where('v.article_id', $articleId)
            ->first(['v.id', 'v.variant_id', 'v.version', 'v.language', 'v.title', 'v.content', 'v.audience_at_publish', DB::raw('v.published_at::text AS published_at'), 'u.name as author_name']);
        if ($version === null) {
            return null;
        }
        $content = json_decode($version->content, true);
        $blocks = [];
        if (is_array($content) && is_array($content['blocks'] ?? null)) {
            foreach (array_slice($content['blocks'], 0, 6) as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $text = $block['text'] ?? null;
                if (! is_string($text) && is_array($block['items'] ?? null)) {
                    $text = implode(' · ', array_filter($block['items'], 'is_string'));
                }
                if (! is_string($text) && is_array($block['rows'] ?? null)) {
                    $text = implode(' / ', array_map(static fn ($r): string => is_array($r) ? implode(' | ', array_filter($r, 'is_string')) : '', $block['rows']));
                }
                $blocks[] = ['type' => is_string($block['type'] ?? null) ? $block['type'] : 'paragraph', 'text' => is_string($text) ? $text : ''];
            }
        }
        /** @var object{status: string, current_version_id: string|null, updated_at: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $version->variant_id)->first(['status', 'current_version_id', DB::raw('updated_at::text AS updated_at')]);
        $current = null;
        $changes = null;
        if ($variant !== null && is_string($variant->current_version_id) && $variant->current_version_id !== $versionId) {
            $currentVersion = DB::table('kb_article_versions')->where('id', $variant->current_version_id)->value('version');
            $current = is_numeric($currentVersion) ? (int) $currentVersion : null;
            try {
                $diff = app(\App\Domain\Kb\Versioning\Versions::class)->diffBetween($versionId, $variant->current_version_id);
                $changes = is_array($diff['summary'] ?? null) ? $diff['summary'] : null;
            } catch (\InvalidArgumentException) {
                $changes = null;
            }
        }
        /** @var object{name: string}|null $category */
        $category = DB::table('kb_categories as c')->join('kb_articles as a', 'a.category_id', '=', 'c.id')->where('a.id', $articleId)->first(['c.name']);
        $name = $category === null ? null : json_decode($category->name, true);

        return [
            'version' => (int) $version->version, 'language' => $version->language, 'title' => $version->title, 'audience' => $version->audience_at_publish,
            'published_at' => $version->published_at, 'author_name' => $version->author_name, 'blocks' => $blocks,
            'variant_id' => $version->variant_id, 'variant_status' => $variant?->status, 'archived_at' => $variant !== null && $variant->status === 'archived' ? $variant->updated_at : null,
            'current_version' => $current, 'current_version_id' => $variant?->current_version_id, 'changes' => $changes,
            'category_name' => is_array($name) && is_string($name['en'] ?? null) ? $name['en'] : null,
        ];
    }
}
