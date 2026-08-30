<?php

declare(strict_types=1);

namespace App\Http\Controllers\Routing;

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Routing\Manual\Broadcast;
use App\Domain\Routing\Manual\Reassign;
use App\Domain\Routing\RoutingRefused;
use App\Domain\Routing\Temporary\PullIn;
use App\Domain\Tickets\Assignment\AlreadyClaimed;
use App\Domain\Tickets\Assignment\AssigneeOutOfScope;
use App\Domain\Tickets\Storage\TicketScope;
use App\Domain\Tickets\Transitions\TransitionNotPermitted;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Doc 11 FR-15 / SEC-01 (S11 step 7) — the supervisor doors. Each route declares its
 * permission and its audit action; the services re-check scope on the target
 * (SEC-01) and refuse with stable codes. Out-of-scope tickets and queues are the
 * 404-PATTERN (C-10: no existence probing), never 403.
 */
final class SupervisorOpsController extends Controller
{
    public function __construct(
        private readonly Reassign $reassign,
        private readonly PullIn $pullIn,
        private readonly Broadcast $broadcast,
        private readonly TicketScope $scope,
        private readonly ListProjector $projector,
    ) {}

    public function reassign(Request $request, string $ticketId): JsonResponse
    {
        try {
            /** @var array{assignee_id: string} $input */
            $input = $request->validate(['assignee_id' => ['required', 'string', 'uuid']]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
        /** @var User $supervisor */
        $supervisor = $request->user();
        if (! $this->scope->visible($supervisor, $ticketId)) {
            return $this->notFound();
        }
        $ticket = Ticket::query()->find($ticketId);
        $target = User::query()->find($input['assignee_id']);
        if (! $ticket instanceof Ticket || ! $target instanceof User) {
            return $this->notFound();
        }

        try {
            $result = $this->reassign->reassign($ticket, $target, $supervisor);
        } catch (AssigneeOutOfScope|AlreadyClaimed|TransitionRefused|TransitionNotPermitted $e) {
            return ProblemDetails::response(ErrorCode::TransitionRejected, 422, $e->getMessage());
        } catch (RoutingRefused $e) {
            return $this->refused($e);
        }
        $this->projector->recomputeTicket($ticketId);

        return new JsonResponse(['ok' => true, ...$result]);
    }

    public function pullIn(Request $request, string $queueId): JsonResponse
    {
        try {
            /** @var array{user_id: string, expires_at?: string|null, reason: string} $input */
            $input = $request->validate([
                'user_id' => ['required', 'string', 'uuid'],
                'expires_at' => ['nullable', 'date'],
                'reason' => ['required', 'string', 'min:10', 'max:500'],
            ]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
        /** @var User $granter */
        $granter = $request->user();
        $rawExpires = $input['expires_at'] ?? null;
        $expires = is_string($rawExpires) && $rawExpires !== '' ? new DateTimeImmutable($rawExpires) : null;

        try {
            $result = $this->pullIn->create($granter, $queueId, $input['user_id'], $expires, $input['reason']);
        } catch (RoutingRefused $e) {
            return $this->refused($e);
        } catch (RuntimeException $e) {
            // Doc 03's own refusals from OverrideAdministration (SEC-04 reason, closed catalog …)
            return ProblemDetails::response(ErrorCode::RoutingRefused, 422, $e->getMessage(), extra: ['refusal_code' => 'grant_refused']);
        }

        return new JsonResponse(['ok' => true, ...$result], 201);
    }

    public function endPullIn(Request $request, string $queueId, string $userId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        try {
            $this->pullIn->end($actor, $queueId, $userId);
        } catch (RoutingRefused $e) {
            return $this->refused($e);
        } catch (RuntimeException $e) {
            return ProblemDetails::response(ErrorCode::RoutingRefused, 422, $e->getMessage(), extra: ['refusal_code' => 'grant_refused']);
        }

        return new JsonResponse(['ok' => true]);
    }

    public function broadcast(Request $request, string $queueId): JsonResponse
    {
        try {
            /** @var array{note: string} $input */
            $input = $request->validate(['note' => ['required', 'string', 'min:1', 'max:2000']]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
        /** @var User $supervisor */
        $supervisor = $request->user();
        try {
            $contract = $this->broadcast->send($supervisor, $queueId, $input['note']);
        } catch (RoutingRefused $e) {
            return $this->refused($e);
        }

        // [D16]: the contract is returned for Doc 16's delivery; nothing else is written here.
        return new JsonResponse(['ok' => true, 'delivery' => $contract]);
    }

    /** Out-of-scope / non-existent targets wear the 404 pattern (C-10); the rest are stable 422 refusals. */
    private function refused(RoutingRefused $e): JsonResponse
    {
        if (in_array($e->refusalCode, ['queue_not_found', 'queue_out_of_scope', 'ticket_out_of_scope', 'agent_not_found', 'pull_in_not_found'], true)) {
            return $this->notFound();
        }

        return ProblemDetails::response(ErrorCode::RoutingRefused, 422, $e->getMessage(), extra: ['refusal_code' => $e->refusalCode]);
    }

    private function notFound(): JsonResponse
    {
        Log::info('supervisor operation 404-pattern served', ['metric' => 'security.cross_tenant_probe']);

        return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found', extra: ['search_instead' => '/inbox']);
    }
}
