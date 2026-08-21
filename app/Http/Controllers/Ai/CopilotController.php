<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Domain\Ai\Copilot\CopilotArtifacts;
use App\Domain\Ai\Copilot\CopilotPanel;
use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Http\Controllers\Controller;
use App\Jobs\Ai\CopilotTaskJob;
use App\Models\Conversation;
use App\Models\TicketListItem;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\Scopes\ScopeSets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Copilot's doors (Doc 09 FR-14/FR-15/FR-16, S09 step 10) — the
 * Doc 07 Copilot-tab + composer-AI-slot fills, backend half.
 *
 * Route → permission from the CLOSED catalog: the panel, the generation
 * doors and discard on ai.copilot.use; insert + sent on
 * ai.copilot.insert_draft (FR-14: "insertion gated by
 * ai.copilot.insert_draft"). Every conversation resolves through the
 * SAME scope filter the composer uses (ScopeSets over TicketListItem);
 * out-of-scope and missing answer alike with the 404 pattern (C-10).
 *
 * NO DOOR HERE SENDS. Generation returns 202 + a request id; the result
 * streams to the agent's user channel and lands as a CARD the agent
 * inserts INTO THE COMPOSER (client-side); the agent's own send is the
 * composer's reply door (Doc 07) — BR-01, structurally: this controller
 * depends on no outbound service and CopilotNeverAutoSendTest asserts it.
 */
final class CopilotController extends Controller
{
    public function __construct(
        private readonly ScopeSets $scopes,
        private readonly CopilotPanel $panel,
        private readonly CopilotArtifacts $artifacts,
    ) {}

    /** FR-14: the panel aggregate (chips live; the rest cached, refreshed by job). */
    public function panel(Request $request, string $conversationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $conversation = $this->resolveScoped($user, $conversationId);
        if ($conversation === null) {
            return $this->notFound();
        }

        return new JsonResponse($this->panel->read($user, $conversation));
    }

    /** FR-14 suggested reply / FR-15 Draft reply — queued; EC-09 accounting lives in the action. */
    public function draft(Request $request, string $conversationId): JsonResponse
    {
        return $this->enqueue($request, $conversationId, 'draft', []);
    }

    /** FR-15: improve | translate | summarize. */
    public function action(Request $request, string $conversationId, string $action): JsonResponse
    {
        if (! in_array($action, ['improve', 'translate', 'summarize'], true)) {
            return $this->notFound();
        }

        try {
            /** @var array{text?: string, target_language?: string} $input */
            $input = $request->validate([
                'text' => [$action === 'summarize' ? 'sometimes' : 'required', 'string', 'max:16384'],
                'target_language' => ['sometimes', 'string', 'in:en,am'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }

        return $this->enqueue($request, $conversationId, $action, $input);
    }

    /** FR-16: insertion = use (inserted_unedited until sent() says otherwise). Gated ai.copilot.insert_draft. */
    public function insert(Request $request, string $artifactId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $artifact = $this->artifacts->find($artifactId, $user->identifier());
        if ($artifact === null || $this->resolveScoped($user, $artifact['conversation_id']) === null) {
            return $this->notFound();
        }

        $this->artifacts->inserted($artifactId, $user->identifier());

        return new JsonResponse(['artifact_id' => $artifactId, 'kind' => $artifact['kind'], 'text' => $artifact['text'], 'outcome' => 'inserted_unedited']);
    }

    /** FR-16: the edited/unedited verdict against the message the agent actually sent. */
    public function sent(Request $request, string $artifactId): JsonResponse
    {
        try {
            /** @var array{message_id: string} $input */
            $input = $request->validate(['message_id' => ['required', 'uuid']]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }

        /** @var User $user */
        $user = $request->user();
        $artifact = $this->artifacts->find($artifactId, $user->identifier());
        if ($artifact === null || $this->resolveScoped($user, $artifact['conversation_id']) === null) {
            return $this->notFound();
        }

        $outcome = $this->artifacts->sent($artifactId, $user->identifier(), $input['message_id']);
        if ($outcome === null) {
            return $this->notFound();
        }

        return new JsonResponse(['artifact_id' => $artifactId, 'outcome' => $outcome]);
    }

    /** FR-16: discarded. */
    public function discard(Request $request, string $artifactId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $artifact = $this->artifacts->find($artifactId, $user->identifier());
        if ($artifact === null || $this->resolveScoped($user, $artifact['conversation_id']) === null) {
            return $this->notFound();
        }

        $this->artifacts->discarded($artifactId, $user->identifier());

        return new JsonResponse(['artifact_id' => $artifactId, 'outcome' => 'discarded']);
    }

    /** @param array{text?: string, target_language?: string} $params */
    private function enqueue(Request $request, string $conversationId, string $action, array $params): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $conversation = $this->resolveScoped($user, $conversationId);
        if ($conversation === null) {
            return $this->notFound();
        }

        $requestId = (string) Str::uuid7();
        CopilotTaskJob::dispatch($requestId, $action, $conversation->identifier(), $user->identifier(), $params);

        return new JsonResponse(['request_id' => $requestId, 'action' => $action, 'channel' => 'user', 'events' => ['ai.copilot.delta', 'ai.copilot.card']], 202);
    }

    private function resolveScoped(User $user, string $conversationId): ?Conversation
    {
        $conversation = Conversation::query()->find($conversationId);
        if ($conversation === null) {
            return null;
        }
        $query = TicketListItem::query()->where('conversation_id', $conversationId);
        $this->scopes->apply($query, 'tickets', $user, ListReadModel::columns());

        return $query->exists() ? $conversation : null;
    }

    private function notFound(): JsonResponse
    {
        Log::info('copilot 404-pattern served', ['metric' => 'security.cross_tenant_probe']);

        return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found', extra: ['search_instead' => '/inbox']);
    }
}
