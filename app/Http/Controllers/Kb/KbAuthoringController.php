<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Authoring\AuthoringTools;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Doc 10 FR-06 doors (S10 step 9): every response is 202 — the draft lands from the `ai` queue; nothing here publishes. */
final class KbAuthoringController extends Controller
{
    public function __construct(private readonly AuthoringTools $tools) {}

    public function fromSource(Request $request): JsonResponse
    {
        try {
            /** @var array{category_id: string, audience: string, language: string, title: string, text: string} $input */
            $input = $request->validate([
                'category_id' => ['required', 'uuid'], 'audience' => ['required', 'in:public,internal'], 'language' => ['required', 'string', 'size:2'],
                'title' => ['required', 'string', 'max:200'], 'text' => ['required', 'string', 'max:60000'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid draft request', errors: $errors);
        }

        return $this->run(fn () => $this->tools->draftFromSource($input['category_id'], $input['audience'], $input['language'], $input['title'], $input['text'], $this->actor($request)));
    }

    public function fromConversations(Request $request): JsonResponse
    {
        try {
            /** @var array{intent: string, category_id: string, audience: string, language: string} $input */
            $input = $request->validate([
                'intent' => ['required', 'string', 'max:64', 'regex:/^[a-z_]+$/'], 'category_id' => ['required', 'uuid'],
                'audience' => ['required', 'in:public,internal'], 'language' => ['required', 'string', 'size:2'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid draft request', errors: $errors);
        }

        return $this->run(fn () => $this->tools->draftFromConversations($input['intent'], $input['category_id'], $input['audience'], $input['language'], $this->actor($request)));
    }

    public function simplify(Request $request, string $variantId): JsonResponse
    {
        return $this->run(fn () => $this->tools->simplify($variantId, $this->actor($request)));
    }

    public function faqVariants(Request $request, string $variantId): JsonResponse
    {
        return $this->run(fn () => $this->tools->faqVariants($variantId, $this->actor($request)));
    }

    /** @param  callable(): array<string, mixed>  $tool */
    private function run(callable $tool): JsonResponse
    {
        try {
            return new JsonResponse($tool(), 202);
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }
    }

    private function actor(Request $request): string
    {
        /** @var User $user */
        $user = $request->user();

        return $user->identifier();
    }
}
