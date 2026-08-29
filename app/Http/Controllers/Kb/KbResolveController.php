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
            return \Inertia\Inertia::render('Kb/Tombstone', $payload + ['t' => __('kb.manager')]);
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
}
