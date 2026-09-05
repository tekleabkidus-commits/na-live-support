<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Kb\Analytics\UsageEvents;
use App\Domain\Kb\Feedback\FeedbackRefused;
use App\Domain\Kb\Feedback\FeedbackWriter;
use App\Domain\Kb\Help\HelpContent;
use App\Domain\Kb\Search\KbSearchIndex;
use App\Http\Controllers\Controller;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Doc 10 FR-18 / US-04 / SEC-05 (S10 step 11) — the widget Help tab's API CONTRACT, on the
 * Doc 02 widget stack (widget-key tenant resolution + visitor token/cookie guard; the visitor
 * is a HASH-shaped principal, never an identity — N-06). PUBLIC + PUBLISHED only (BR-01;
 * HelpContent is the read model). Every response carries `start_chat: true` — the tab's
 * escape hatch is a render FACT, present even (especially) when search is down or empty
 * (US-04's arc: search → 👎 → start chat). Rendering is Doc 13's ([D13]); this contract is
 * what it renders. Search FS-02: Meilisearch down → `unavailable: true`, never a 500 and
 * never an empty lie. Feedback: the SEC-05 endpoint legs — 429 rate-limited, 422 over-cap —
 * over the step-10 writer (dedupe arbiter, inert storage, anomaly event). The viewed event's
 * conversation attribution rides [D13] (the in-chat widget knows its conversation; the Help
 * tab alone does not).
 */
final class KbHelpController extends Controller
{
    public function __construct(
        private readonly HelpContent $help,
        private readonly KbSearchIndex $index,
        private readonly UsageEvents $usage,
        private readonly FeedbackWriter $feedback,
    ) {}

    public function categories(): JsonResponse
    {
        return new JsonResponse($this->help->tree() + ['start_chat' => true]);
    }

    public function search(Request $request): JsonResponse
    {
        $q = $request->query('q');
        $q = is_string($q) ? mb_substr($q, 0, 200) : '';
        $language = $request->query('language');
        $filter = ['visibility = public', 'status = published']; // the widget NEVER widens — no parameter exists
        if (is_string($language) && preg_match('/^[a-z]{2,8}$/', $language) === 1) {
            $filter[] = 'language = '.$language;
        }
        try {
            $hits = $this->index->client()->index($this->index->indexName())->search($q, ['filter' => $filter, 'limit' => 10])->getHits();
        } catch (Throwable) {
            return new JsonResponse(['unavailable' => true, 'hits' => [], 'start_chat' => true]); // FS-02: degrade honestly; the escape hatch stays
        }
        $shaped = array_map(static fn (array $hit): array => [
            'article_id' => $hit['article_id'] ?? null, 'title' => $hit['title'] ?? null,
            'summary' => $hit['summary'] ?? null, 'language' => $hit['language'] ?? null, 'slug' => $hit['slug'] ?? null,
        ], $hits);

        return new JsonResponse(['unavailable' => false, 'hits' => $shaped, 'start_chat' => true]);
    }

    public function article(Request $request, string $articleId): JsonResponse
    {
        $chain = $this->chain($request);
        $article = $this->help->article($articleId, $chain);
        if ($article === null) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found'); // C-10: internal/unknown are indistinguishable here
        }
        /** @var string $variantId */
        $variantId = $article['variant_id'];
        DB::transaction(fn () => $this->usage->viewed($variantId, 'widget')); // FR-12; the outbox row needs its transaction (Doc 01 T-03) — found live at S13 step 9 (P-KB-VIEWED)

        return new JsonResponse($article + ['start_chat' => true]);
    }

    public function feedback(Request $request): JsonResponse
    {
        $variantId = $request->input('variant_id');
        $score = $request->input('score');
        $comment = $request->input('comment');
        if (! is_string($variantId) || ! is_int($score)) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'variant_id and an integer score are required');
        }
        /** @var \App\Domain\Auth\Widget\WidgetVisitor $visitor */
        $visitor = $request->user('widget'); // this surface names its guard explicitly (AuthenticateWidgetVisitor's law)
        try {
            $result = $this->feedback->customer($variantId, 'w:'.$visitor->getAuthIdentifier(), $score, is_string($comment) ? $comment : null, 'widget');
        } catch (FeedbackRefused $e) {
            return $e->reason === 'rate_limited'
                ? ProblemDetails::response(ErrorCode::RateLimited, 429, $e->getMessage())
                : ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse($result + ['start_chat' => true], 201);
    }

    /**
     * FR-05: the customer media door — the STABLE path behind which signing rotates.
     * Serves ONLY when the article is public with a published variant AND the file
     * exists; anything else is 404 (C-10 — internal, draft, deleted and unknown are
     * indistinguishable from absent).
     */
    public function media(string $articleId, string $file): \Symfony\Component\HttpFoundation\Response
    {
        $servable = DB::table('kb_articles as a')
            ->join('kb_article_variants as v', 'v.article_id', '=', 'a.id')
            ->where('a.id', $articleId)->where('a.audience', 'public')->whereNull('a.deleted_at')
            ->where('v.status', 'published')->exists();
        if (! $servable) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }
        try {
            $key = app(\App\Domain\Kb\Media\ArticleMedia::class)->key($articleId, $file);
            if (! app(\App\Domain\Kb\Media\ArticleMedia::class)->exists($key)) {
                return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
            }

            return new \Illuminate\Http\RedirectResponse(app(\App\Domain\Conversations\Media\MediaStore::class)->signedUrl($key, 'clean'), 302);
        } catch (Throwable) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }
    }

    /** @return list<string> C-08: requested language → tenant default → en. */
    private function chain(Request $request): array
    {
        $chain = [];
        $requested = $request->query('language');
        if (is_string($requested) && preg_match('/^[a-z]{2,8}$/', $requested) === 1) {
            $chain[] = $requested;
        }
        $tenantDefault = app(\App\Support\Settings\SettingsRepository::class)->get('tenant.default_language');
        if (is_string($tenantDefault) && $tenantDefault !== '') {
            $chain[] = $tenantDefault;
        }
        $chain[] = 'en';

        return array_values(array_unique($chain));
    }
}
