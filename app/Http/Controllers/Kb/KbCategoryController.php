<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Categories\CategoryHasArticles;
use App\Domain\Kb\Categories\CategoryTree;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Doc 10 FR-01 / EC-03 — the category doors (S10 step 3; JSON only — the manager UI
 * is step 12 [B9]). Gated kb.categories.manage; every mutation audits through the
 * domain door; the delete refusal carries the EC-03 dialog facts (articles +
 * retrieval impact) as Problem extras.
 */
final class KbCategoryController extends Controller
{
    public function __construct(private readonly CategoryTree $tree) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['categories' => $this->tree->tree()]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            /** @var array{name: array<string, string>, slug: string, parent_id?: string|null, ai_usable?: bool, visibility_default?: string, position?: int, icon?: string|null} $input */
            $input = $request->validate([
                'name' => ['required', 'array', 'min:1'], 'name.*' => ['required', 'string', 'max:120'],
                'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'], 'parent_id' => ['nullable', 'uuid'],
                'ai_usable' => ['sometimes', 'boolean'], 'visibility_default' => ['sometimes', 'in:public,internal'],
                'position' => ['sometimes', 'integer', 'min:0', 'max:10000'], 'icon' => ['nullable', 'string', 'max:40'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid category', errors: $errors);
        }
        try {
            $id = $this->tree->create($input['name'], $input['slug'], $input['parent_id'] ?? null, (bool) ($input['ai_usable'] ?? false), $input['visibility_default'] ?? 'public', $user->identifier(), (int) ($input['position'] ?? 0), $input['icon'] ?? null);
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse(['id' => $id], 201);
    }

    public function update(Request $request, string $categoryId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            /** @var array<string, mixed> $input */
            $input = $request->validate([
                'name' => ['sometimes', 'array', 'min:1'], 'name.*' => ['string', 'max:120'], 'slug' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'],
                'ai_usable' => ['sometimes', 'boolean'], 'visibility_default' => ['sometimes', 'in:public,internal'],
                'position' => ['sometimes', 'integer', 'min:0', 'max:10000'], 'icon' => ['sometimes', 'nullable', 'string', 'max:40'], 'description' => ['sometimes', 'array'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid category', errors: $errors);
        }
        try {
            $this->tree->update($categoryId, $input, $user->identifier());
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }

        return new JsonResponse(['id' => $categoryId, 'updated' => true]);
    }

    /** EC-03: the dialog fact before the move — "N articles will leave the chatbot's knowledge if moved to X". */
    public function impact(Request $request, string $categoryId): JsonResponse
    {
        $target = $request->query('to');

        return new JsonResponse(['retrieval_impact' => $this->tree->retrievalImpact($categoryId, is_string($target) ? $target : null)]);
    }

    public function reassign(Request $request, string $categoryId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $to = $request->input('to');
        if (! is_string($to)) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'to is required');
        }
        try {
            $moved = $this->tree->reassign($categoryId, $to, $user->identifier());
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse(['moved' => $moved]);
    }

    public function destroy(Request $request, string $categoryId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $this->tree->delete($categoryId, $user->identifier());
        } catch (CategoryHasArticles $e) {
            return ProblemDetails::response(ErrorCode::KbCategoryHasArticles, 409, $e->getMessage(), extra: ['articles' => $e->articles, 'retrieval_impact' => $e->retrievalImpact]);
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse(['deleted' => true]);
    }
}
