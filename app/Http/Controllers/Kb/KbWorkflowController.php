<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Review\ReviewComments;
use App\Domain\Kb\Visibility\Visibility;
use App\Domain\Kb\Workflow\TransitionRefused;
use App\Domain\Kb\Workflow\Transitions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Doc 10 FR-07 / FR-13 / EC-11 — the workflow doors (S10 step 4; JSON only): submit
 * (kb.articles.edit), request-changes / approve / publish / unpublish / archive /
 * un-archive (kb.articles.publish), the review thread, and the ONE visibility door
 * (kb.articles.publish — EC-11 is a publish, not a toggle). A refused transition is a
 * 409 transition_rejected Problem carrying the facts (the missing-alt list, the
 * sanitizer flags) as extras — the publish dialog renders them.
 */
final class KbWorkflowController extends Controller
{
    public function __construct(private readonly Transitions $transitions, private readonly Visibility $visibility, private readonly ReviewComments $comments) {}

    public function submit(Request $request, string $variantId): JsonResponse
    {
        return $this->run(fn () => $this->transitions->submit($variantId, $this->actor($request)->identifier()), ['status' => 'in_review']);
    }

    public function requestChanges(Request $request, string $variantId): JsonResponse
    {
        $comment = $request->input('comment');
        if (! is_string($comment) || trim($comment) === '' || mb_strlen($comment) > 2000) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'a comment (≤ 2000 chars) is required to request changes');
        }
        $anchor = $request->input('block_anchor');

        return $this->run(fn () => $this->transitions->requestChanges($variantId, $this->actor($request)->identifier(), $comment, is_string($anchor) ? $anchor : null), ['status' => 'draft']);
    }

    /** Approve (from In review) and self-publish (from Draft) share the door — the audit names which happened. */
    public function publish(Request $request, string $variantId): JsonResponse
    {
        $note = $request->input('note');
        $override = $request->input('sanitizer_override');
        try {
            $reason = is_array($override) ? ($override['reason'] ?? '') : '';
            $result = $this->transitions->publish($variantId, $this->actor($request), is_string($note) ? mb_substr($note, 0, 500) : null, is_array($override) ? ['reason' => is_string($reason) ? $reason : ''] : null);
        } catch (TransitionRefused $e) {
            return ProblemDetails::response(ErrorCode::TransitionRejected, 409, $e->getMessage(), extra: $e->facts);
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }

        return new JsonResponse($result + ['status' => 'published']);
    }

    public function unpublish(Request $request, string $variantId): JsonResponse
    {
        return $this->run(fn () => $this->transitions->unpublish($variantId, $this->actor($request)->identifier()), ['status' => 'draft']);
    }

    public function archive(Request $request, string $variantId): JsonResponse
    {
        return $this->run(fn () => $this->transitions->archive($variantId, $this->actor($request)->identifier()), ['status' => 'archived']);
    }

    public function unarchive(Request $request, string $variantId): JsonResponse
    {
        return $this->run(fn () => $this->transitions->unarchive($variantId, $this->actor($request)->identifier()), ['status' => 'draft']);
    }

    /** EC-11: the ONE door that changes an article's audience — high-visibility audited, full pipeline (review) applies. */
    public function visibility(Request $request, string $articleId): JsonResponse
    {
        $audience = $request->input('audience');
        if (! is_string($audience) || ! in_array($audience, ['public', 'internal'], true)) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'audience is public or internal');
        }
        try {
            $result = $this->visibility->change($articleId, $audience, $this->actor($request)->identifier());
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }
        if ($result['old'] === $result['new']) {
            $request->attributes->set('audit.noop', true); // same-state: nothing changed, nothing audited (the A-37 shape)
        }

        return new JsonResponse($result);
    }

    public function comments(string $variantId): JsonResponse
    {
        return new JsonResponse(['comments' => $this->comments->thread($variantId), 'open' => $this->comments->openCount($variantId)]);
    }

    public function comment(Request $request, string $variantId): JsonResponse
    {
        $body = $request->input('body');
        if (! is_string($body) || trim($body) === '' || mb_strlen($body) > 2000) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'body (≤ 2000 chars) is required');
        }
        $anchor = $request->input('block_anchor');
        $version = $request->input('version_id');
        try {
            $id = $this->comments->add($variantId, is_string($version) ? $version : null, is_string($anchor) ? $anchor : null, $body, $this->actor($request)->identifier());
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }

        return new JsonResponse(['id' => $id], 201);
    }

    public function resolveComment(Request $request, string $commentId): JsonResponse
    {
        try {
            $this->comments->resolve($commentId, $this->actor($request)->identifier());
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }

        return new JsonResponse(['resolved' => true]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @param array<string, mixed> $ok */
    private function run(callable $transition, array $ok): JsonResponse
    {
        try {
            $transition();
        } catch (TransitionRefused $e) {
            return ProblemDetails::response(ErrorCode::TransitionRejected, 409, $e->getMessage(), extra: $e->facts);
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }

        return new JsonResponse($ok);
    }
}
