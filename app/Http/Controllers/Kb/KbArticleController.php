<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Articles\ArticleWriter;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Doc 10 FR-02 — article + variant doors (S10 step 3; JSON only). create →
 * kb.articles.create; variants/meta/move → kb.articles.edit. INTERNAL articles are
 * ABSENT (404, C-10) for readers without kb.view_internal — absence, not redaction.
 */
final class KbArticleController extends Controller
{
    public function __construct(private readonly ArticleWriter $writer) {}

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            /** @var array{category_id: string, audience: string, source_language: string, title: string, tags?: list<string>} $input */
            $input = $request->validate([
                'category_id' => ['required', 'uuid'], 'audience' => ['required', 'in:public,internal'],
                'source_language' => ['required', 'string', 'size:2'], 'title' => ['required', 'string', 'max:200'],
                'tags' => ['sometimes', 'array', 'max:20'], 'tags.*' => ['string', 'max:40'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid article', errors: $errors);
        }
        try {
            $created = $this->writer->create($input['category_id'], $input['audience'], $input['source_language'], $input['title'], $user->identifier(), $input['tags'] ?? []);
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse($created, 201);
    }

    public function show(Request $request, string $articleId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var object{id: string, category_id: string, audience: string, source_language: string, tags: string, seeded: bool}|null $article */
        $article = DB::table('kb_articles')->where('id', $articleId)->whereNull('deleted_at')->first();
        if ($article === null || ($article->audience === 'internal' && ! app(\App\Support\Rbac\CapabilityMapProvider::class)->verdictFor($user, 'kb.view_internal')->allowed)) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }
        $variants = DB::table('kb_article_variants')->where('article_id', $articleId)->orderBy('language')
            ->get(['id', 'language', 'status', 'translation_state', 'translation_source', 'title', 'slug', 'summary', 'index_status', 'current_version_id'])->all();

        return new JsonResponse([
            'article' => ['id' => $article->id, 'category_id' => $article->category_id, 'audience' => $article->audience, 'source_language' => $article->source_language, 'tags' => json_decode($article->tags, true), 'seeded' => (bool) $article->seeded],
            'variants' => $variants,
        ]);
    }

    public function addVariant(Request $request, string $articleId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            /** @var array{language: string, title: string} $input */
            $input = $request->validate(['language' => ['required', 'string', 'size:2'], 'title' => ['required', 'string', 'max:200']]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid variant', errors: $errors);
        }
        try {
            $variant = $this->writer->addVariant($articleId, $input['language'], $input['title'], $user->identifier());
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse($variant, 201);
    }

    public function updateMeta(Request $request, string $variantId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            /** @var array<string, mixed> $input */
            $input = $request->validate(['title' => ['sometimes', 'string', 'max:200'], 'slug' => ['sometimes', 'string', 'max:80'], 'summary' => ['sometimes', 'string', 'max:500'], 'tags' => ['sometimes', 'array', 'max:20'], 'related_article_ids' => ['sometimes', 'array', 'max:20'], 'related_article_ids.*' => ['uuid']]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid metadata', errors: $errors);
        }
        try {
            $this->writer->updateMeta($variantId, $input, $user->identifier());
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }

        return new JsonResponse(['updated' => true]);
    }

    public function move(Request $request, string $articleId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $to = $request->input('category_id');
        if (! is_string($to)) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'category_id is required');
        }
        try {
            $this->writer->move($articleId, $to, $user->identifier());
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }

        return new JsonResponse(['moved' => true]);
    }
}
