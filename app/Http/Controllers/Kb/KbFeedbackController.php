<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Analytics\ArticleStats;
use App\Domain\Kb\Feedback\FeedbackRefused;
use App\Domain\Kb\Feedback\FeedbackWriter;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Doc 10 §7 stats header + FR-11 staff notes + actioned (S10 step 10). Customer-facing feedback rides the widget/Telegram surfaces (step 11). */
final class KbFeedbackController extends Controller
{
    public function __construct(private readonly ArticleStats $stats, private readonly FeedbackWriter $feedback) {}

    public function stats(Request $request, string $articleId): JsonResponse
    {
        return new JsonResponse($this->stats->forArticle($articleId));
    }

    public function index(Request $request, string $articleId): JsonResponse
    {
        $cursor = $request->query('cursor');
        $limit = (int) $request->query('limit', '50');

        return new JsonResponse($this->stats->feedbackList($articleId, is_string($cursor) ? $cursor : null, $limit));
    }

    public function note(Request $request, string $variantId): JsonResponse
    {
        $comment = $request->input('comment');
        try {
            return new JsonResponse($this->feedback->staffNote($variantId, $this->actor($request), is_string($comment) ? $comment : ''), 201);
        } catch (FeedbackRefused $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }
    }

    public function action(Request $request, string $feedbackId): JsonResponse
    {
        try {
            $this->feedback->action($feedbackId, $this->actor($request));
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }

        return new JsonResponse(['actioned' => true]);
    }

    private function actor(Request $request): string
    {
        /** @var User $user */
        $user = $request->user();

        return $user->identifier();
    }
}
