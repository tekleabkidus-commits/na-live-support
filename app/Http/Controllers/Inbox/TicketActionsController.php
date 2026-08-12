<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Domain\Tickets\Assignment\AlreadyClaimed;
use App\Domain\Tickets\Assignment\AssigneeOutOfScope;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Priority\ChangePriority;
use App\Domain\Tickets\Tags\TicketTags;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketListItem;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\Scopes\ScopeSets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * S07 step 13: thin HTTP doors over Doc 06's headless property services, minted
 * the way step 12 minted the transition door. Doc 07 RENDERS and READS — every
 * verdict, audit entry, and event below is the owning service's own (AssignTicket,
 * ChangePriority, TicketTags all check their permission key and audit internally);
 * this controller only resolves scope, maps refusals to C-04 codes, and nudges the
 * projector so the caller's list converges without waiting for the relay.
 *
 * Consumers: FR-31's I/A/P/T bindings and their palettes, and B2-S01's mobile
 * swipe-claim + actions sheet. The route-level permission middleware repeats the
 * services' keys because the route→permission mapping gate demands the mapping;
 * the service check remains the enforcement of record (two layers, one truth).
 *
 * Scope resolution mirrors ComposerController: a ticket is visible iff its
 * TicketListItem row survives the requester's ScopeSets — misses get the C-10
 * 404-pattern (no existence oracle) with the security metric incremented.
 */
final class TicketActionsController extends Controller
{
    public function __construct(
        private readonly AssignTicket $assignment,
        private readonly ChangePriority $priority,
        private readonly TicketTags $tags,
        private readonly ListProjector $projector,
        private readonly ScopeSets $scopes,
    ) {}

    /** FR-31 "I" / mobile swipe-right: Doc 06 FR-16's claim door, race intact. */
    public function claim(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ticket = $this->resolveScoped($user, $ticketId);
        if ($ticket === null) {
            return $this->notFound();
        }

        try {
            $ticket = $this->assignment->claim($ticket, $user);
        } catch (AlreadyClaimed $race) {
            return ProblemDetails::response(ErrorCode::AlreadyClaimed, 409, $race->getMessage(), extra: ['by' => $race->by]);
        }
        $this->projector->recomputeTicket($ticket->identifier());

        return new JsonResponse(['ok' => true, 'assignee_id' => $user->identifier()]);
    }

    /** B2-S01's undo strip: the claim's inverse through the same owning service. */
    public function unassign(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ticket = $this->resolveScoped($user, $ticketId);
        if ($ticket === null) {
            return $this->notFound();
        }

        $ticket = $this->assignment->unassign($ticket, $user);
        $this->projector->recomputeTicket($ticket->identifier());

        return new JsonResponse(['ok' => true]);
    }

    /** FR-31 "A": Doc 06 FR-15's assign-others door. */
    public function assign(Request $request, string $ticketId): JsonResponse
    {
        try {
            /** @var array{assignee_id: string} $input */
            $input = $request->validate(['assignee_id' => ['required', 'string', 'uuid']]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }

        /** @var User $user */
        $user = $request->user();
        $ticket = $this->resolveScoped($user, $ticketId);
        if ($ticket === null) {
            return $this->notFound();
        }

        $assignee = User::query()->find($input['assignee_id']);
        if (! $assignee instanceof User || $assignee->isDisabled() || $assignee->isServiceAccount()) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'assignee not assignable', errors: ['assignee_id' => ['not assignable']]);
        }

        try {
            $ticket = $this->assignment->assign($ticket, $assignee, $user);
        } catch (AssigneeOutOfScope $out) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $out->getMessage(), errors: ['assignee_id' => ['out of scope']]);
        }
        $this->projector->recomputeTicket($ticket->identifier());

        return new JsonResponse(['ok' => true, 'assignee_id' => $assignee->identifier()]);
    }

    /** FR-31 "P" / the actions sheet's priority row: Doc 06 FR-17, four fixed levels. */
    public function priority(Request $request, string $ticketId): JsonResponse
    {
        // validate() caught locally: the platform's shouldRenderJsonWhen only
        // covers api/* — a JSON caller failing validation on a web-group route
        // otherwise crashes into the HTML redirect path (discovered by this
        // door's own refusal test; filed as a step-13 note — SelectionController
        // shares the latent shape).
        try {
            /** @var array{priority: string} $input */
            $input = $request->validate(['priority' => ['required', 'string', 'in:low,normal,high,urgent']]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }

        /** @var User $user */
        $user = $request->user();
        $ticket = $this->resolveScoped($user, $ticketId);
        if ($ticket === null) {
            return $this->notFound();
        }

        $ticket = $this->priority->change($ticket, $input['priority'], $user);
        $this->projector->recomputeTicket($ticket->identifier());

        return new JsonResponse(['ok' => true, 'priority' => $input['priority']]);
    }

    /** FR-31 "T": Doc 06 FR-19 tag ADD — writes ticket.tagged (declared on the route). */
    public function tagsAdd(Request $request, string $ticketId): JsonResponse
    {
        return $this->tagWrite($request, $ticketId, add: true);
    }

    /** FR-31 "T": Doc 06 FR-19 tag REMOVE — writes ticket.untagged (declared on the route). */
    public function tagsRemove(Request $request, string $ticketId): JsonResponse
    {
        return $this->tagWrite($request, $ticketId, add: false);
    }

    private function tagWrite(Request $request, string $ticketId, bool $add): JsonResponse
    {
        try {
            /** @var array{tags: list<string>} $input */
            $input = $request->validate([
                'tags' => ['required', 'array', 'min:1', 'max:20'],
                'tags.*' => ['string', 'max:48'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }

        /** @var User $user */
        $user = $request->user();
        $ticket = $this->resolveScoped($user, $ticketId);
        if ($ticket === null) {
            return $this->notFound();
        }

        $ticket = $add
            ? $this->tags->add($ticket, $input['tags'], $user)
            : $this->tags->remove($ticket, $input['tags'], $user);
        $this->projector->recomputeTicket($ticket->identifier());

        return new JsonResponse(['ok' => true, 'tags' => $this->tags->tagsOf($ticket)]);
    }

    /**
     * The A-palette's directory: active, human, tenant-scoped staff. RLS scopes the
     * rows; the palette needs names to pick a target, nothing more — id + name only,
     * no presence, no workload (Doc 15's surface, not this one).
     */
    public function assignees(Request $request): JsonResponse
    {
        $q = $request->query('q');
        $query = User::query()
            ->whereNull('disabled_at')
            ->whereNull('service_account_at')
            ->orderBy('name');
        if (is_string($q) && $q !== '') {
            $query->where('name', 'ilike', str_replace(['%', '_'], ['\%', '\_'], $q).'%');
        }

        return new JsonResponse([
            'assignees' => $query->limit(8)->get(['id', 'name'])
                ->map(fn (User $u): array => ['id' => $u->identifier(), 'name' => is_string($n = $u->getAttribute('name')) ? $n : ''])
                ->all(),
        ]);
    }

    /** The T-palette's autocomplete: Doc 06 FR-19's suggest, RLS-scoped. */
    public function suggestTags(Request $request): JsonResponse
    {
        $q = $request->query('q');

        return new JsonResponse(['tags' => $this->tags->suggest(is_string($q) ? $q : '', 8)]);
    }

    private function resolveScoped(User $user, string $ticketId): ?Ticket
    {
        $query = TicketListItem::query()->where('ticket_id', $ticketId);
        $this->scopes->apply($query, 'tickets', $user, ListReadModel::columns());
        if (! $query->exists()) {
            return null;
        }

        return Ticket::query()->find($ticketId);
    }

    private function notFound(): JsonResponse
    {
        Log::info('inbox ticket-action 404-pattern served', ['metric' => 'security.cross_tenant_probe']);

        return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found', extra: ['search_instead' => '/inbox']);
    }
}
