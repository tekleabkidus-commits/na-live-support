<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Tombstones\CitationLinks;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Events\EventPublisher;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Doc 10 FR-12 / Doc 09 FR-14 (S10 step 7b, packet P3 — the disclosed refinement): the
 * Copilot card's insert-as-link is the agent's ACT, and this Doc 10 door records it —
 * kb.article.inserted_by_agent fires HERE, at the click, never on render; the card's kb
 * link was never a Copilot artifact, so Doc 09's CopilotController stays untouched.
 * Gated ai.copilot.insert_draft (FR-14's own key: it IS the insert act). BR-01 untouched:
 * the returned line lands in the composer as a DRAFT; nothing sends.
 */
final class KbInsertLinkController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly CitationLinks $links, private readonly EventPublisher $events) {}

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $articleId = $request->input('article_id');
        $conversationId = $request->input('conversation_id');
        if (! is_string($articleId) || ! is_string($conversationId)) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'article_id and conversation_id are required');
        }
        $link = $this->links->forArticle($articleId);
        if ($link === null) {
            return new JsonResponse(['text' => '📄 '.$articleId, 'recorded' => false]); // a legacy ref — insert the bare ref, no event to mint
        }
        DB::transaction(fn () => $this->events->publish('kb.article.inserted_by_agent', [
            'tenant_id' => $this->context->tenantId(), 'article_id' => $articleId, 'version_id' => $link['version_id'],
            'conversation_id' => $conversationId, 'agent_id' => $user->identifier(),
        ]));

        return new JsonResponse(['text' => '📄 '.$link['title'].' '.$link['url'], 'recorded' => true]);
    }
}
