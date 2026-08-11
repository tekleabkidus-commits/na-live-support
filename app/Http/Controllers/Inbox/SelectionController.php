<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Selection\SelectionTokens;
use App\Domain\Tickets\Bulk\BulkOperation;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * T-05's HTTP doors (S07 step 7). Mint rides tickets.view (selecting what
 * you can already see); execute carries the audit tripwire
 * (inbox.selection_executed — a wide mutation with actor + counts, SEC-03
 * posture) and the PER-ITEM permissions stay Doc 06's live checks (SEC-04
 * structural — nothing here holds a verdict).
 */
final class SelectionController extends Controller
{
    public function store(Request $request, SelectionTokens $tokens): JsonResponse
    {
        /** @var array{view_key: string, filters?: array<string, mixed>} $input */
        $input = $request->validate([
            'view_key' => ['required', 'string', 'max:200'],
            'filters' => ['sometimes', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $minted = $tokens->mint($user, $input['view_key'], $input['filters'] ?? []);
        } catch (DomainException) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, __('inbox.selection.empty'));
        }

        return new JsonResponse($minted, 201);
    }

    public function execute(Request $request, string $tokenId, SelectionTokens $tokens, AuditLogger $audit): JsonResponse
    {
        /** @var array{action: string, params?: array<string, mixed>} $input */
        $input = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', BulkOperation::ACTIONS)],
            'params' => ['sometimes', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $tokens->execute($user, $tokenId, $input['action'], $input['params'] ?? []);
        } catch (DomainException $e) {
            return $e->getMessage() === 'token_consumed'
                ? ProblemDetails::response(ErrorCode::ValidationFailed, 409, __('inbox.selection.consumed'))
                : ProblemDetails::response(ErrorCode::ViewNotFound, 404, __('foundation.error.view_not_found'));
        }

        $audit->record(
            actionKey: 'inbox.selection_executed',
            entity: 'inbox_selection_token',
            entityId: $tokenId,
            actorType: AuditActor::Staff,
            payload: ['action' => $input['action'], 'resolved' => $result['resolved'], 'skipped' => $result['skipped']],
            actorId: $user->identifier(),
        );

        return new JsonResponse($result);
    }
}
