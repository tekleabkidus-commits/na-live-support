<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Domain\Tickets\Escalation\EscalateTicket;
use App\Domain\Tickets\Escalation\EscalationPath;
use App\Domain\Tickets\Merge\UnmergeTicket;
use App\Domain\Tickets\Reopen\ReopenTicket;
use App\Domain\Tickets\Spam\SpamTicket;
use App\Domain\Tickets\Split\SplitTicket;
use App\Domain\Tickets\Transfer\TransferTicket;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketListItem;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\Scopes\ScopeSets;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * B1-S04's operation doors (S07 step 14) — the split/escalate/spam/transfer
 * dialogs, C7's unmerge, and C9's reopen, all thin over Doc 06's owning
 * services (SplitTicket, EscalateTicket, SpamTicket, TransferTicket,
 * UnmergeTicket, ReopenTicket). Every verdict, window check, cap, snapshot,
 * event and audit entry below is the service's own; this controller resolves
 * scope (C-10 404-pattern), maps refusals to C-04 codes, and nudges the
 * projector.
 *
 * The reopen door exists because the generic transition door CANNOT carry
 * closed→open honestly: that edge demands a REAL written reason (the matrix's
 * own rule) and the window check + assignee reconcile live in
 * ReopenTicket::reopenByStaff — which this door gives its first caller.
 */
final class TicketOperationsController extends Controller
{
    public function __construct(
        private readonly SplitTicket $splits,
        private readonly EscalateTicket $escalations,
        private readonly SpamTicket $spam,
        private readonly TransferTicket $transfers,
        private readonly UnmergeTicket $unmerges,
        private readonly ReopenTicket $reopens,
        private readonly ListProjector $projector,
        private readonly ScopeSets $scopes,
    ) {}

    /** C7: FR-20's reversal — window, snapshot and refusals all the service's. */
    public function unmerge(Request $request, string $ticketId): JsonResponse
    {
        return $this->door(
            $request,
            $ticketId,
            rules: [],
            operation: function (Ticket $ticket, User $user, array $input): array {
                $restored = $this->unmerges->unmerge($ticket, $user);

                return ['ticket_id' => $restored->identifier(), 'status' => $restored->getAttribute('status')];
            },
            alsoRecompute: static function (Ticket $ticket): array {
                $target = $ticket->getAttribute('merged_into');

                return is_string($target) ? [$target] : [];
            },
        );
    }

    /** C10: FR-09 mark — the 7-day unmark window starts at the trigger's stamp. */
    public function spam(Request $request, string $ticketId): JsonResponse
    {
        return $this->door(
            $request,
            $ticketId,
            rules: [
                'reason' => ['required', 'string', 'min:10', 'max:500'],
                'block_conversation' => ['sometimes', 'boolean'],
            ],
            operation: function (Ticket $ticket, User $user, array $input): array {
                /** @var array{reason: string, block_conversation?: bool} $input */
                $marked = $this->spam->mark($ticket, $user, $input['reason'], $input['block_conversation'] ?? false);

                return ['status' => $marked->getAttribute('status')];
            },
        );
    }

    /** C10's "Not spam" — inside the 7 days; final after (the service refuses). */
    public function unspam(Request $request, string $ticketId): JsonResponse
    {
        return $this->door(
            $request,
            $ticketId,
            rules: ['reason' => ['required', 'string', 'min:10', 'max:500']],
            operation: function (Ticket $ticket, User $user, array $input): array {
                /** @var array{reason: string} $input */
                $restored = $this->spam->unmark($ticket, $user, $input['reason']);

                return ['status' => $restored->getAttribute('status')];
            },
        );
    }

    /** C11: FR-23 — three fixed paths; the named target re-validated by the service. */
    public function escalate(Request $request, string $ticketId): JsonResponse
    {
        return $this->door(
            $request,
            $ticketId,
            rules: [
                'path' => ['required', 'in:supervisor_review,department,named'],
                'target_id' => ['required_unless:path,supervisor_review', 'nullable', 'string', 'uuid'],
                'reason' => ['required', 'string', 'min:10', 'max:500'],
            ],
            operation: function (Ticket $ticket, User $user, array $input): array {
                /** @var array{path: string, target_id?: string|null, reason: string} $input */
                $path = match ($input['path']) {
                    'supervisor_review' => EscalationPath::supervisorReview(),
                    'department' => EscalationPath::department($input['target_id'] ?? ''),
                    default => EscalationPath::named($input['target_id'] ?? ''),
                };
                $escalated = $this->escalations->escalate($ticket, $user, $path, $input['reason']);

                return ['status' => $escalated->getAttribute('status')];
            },
        );
    }

    /** FR-23: de-escalation returns to open with a note. */
    public function deescalate(Request $request, string $ticketId): JsonResponse
    {
        return $this->door(
            $request,
            $ticketId,
            rules: ['note' => ['required', 'string', 'min:10', 'max:500']],
            operation: function (Ticket $ticket, User $user, array $input): array {
                /** @var array{note: string} $input */
                $restored = $this->escalations->deescalate($ticket, $user, $input['note']);

                return ['status' => $restored->getAttribute('status')];
            },
        );
    }

    /** D11/FR-21: the split — the cap of 3 is the service's own refusal. */
    public function split(Request $request, string $ticketId): JsonResponse
    {
        return $this->door(
            $request,
            $ticketId,
            rules: [
                'message_ids' => ['required', 'array', 'min:1', 'max:100'],
                'message_ids.*' => ['string', 'uuid'],
                'subject' => ['sometimes', 'nullable', 'string', 'max:120'],
                'department_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            ],
            operation: function (Ticket $ticket, User $user, array $input): array {
                /** @var array{message_ids: list<string>, subject?: string|null, department_id?: string|null} $input */
                $minted = $this->splits->split(
                    $ticket,
                    $input['message_ids'],
                    $user,
                    departmentId: $input['department_id'] ?? null,
                    subject: $input['subject'] ?? null,
                );
                $this->projector->recomputeTicket($minted->identifier());

                return ['ticket_id' => $minted->identifier(), 'number' => $minted->getAttribute('number')];
            },
        );
    }

    /** D4's cause: FR-22 department transfer through the owning service. */
    public function transfer(Request $request, string $ticketId): JsonResponse
    {
        return $this->door(
            $request,
            $ticketId,
            rules: ['department_id' => ['required', 'string', 'uuid']],
            operation: function (Ticket $ticket, User $user, array $input): array {
                /** @var array{department_id: string} $input */
                $moved = $this->transfers->transfer($ticket, $input['department_id'], $user);

                return ['department_id' => $moved->getAttribute('department_id')];
            },
        );
    }

    /**
     * C9: the staff reopen — the REAL written reason the closed→open edge
     * demands (the generic transition door refuses that edge; see
     * ComposerController). Window + assignee reconcile are the service's.
     */
    public function reopen(Request $request, string $ticketId): JsonResponse
    {
        return $this->door(
            $request,
            $ticketId,
            rules: ['reason' => ['required', 'string', 'min:3', 'max:500']],
            operation: function (Ticket $ticket, User $user, array $input): array {
                /** @var array{reason: string} $input */
                $result = $this->reopens->reopenByStaff($ticket, $user, $input['reason']);

                return ['status' => $result->ticket->getAttribute('status')];
            },
        );
    }

    /**
     * C9 lapsed (advisor ruling 2026-08-13, stop item 2): the DOCUMENT wins —
     * after the reopen window the affordance is FR-08's new-ticket-and-link,
     * never a widened reopen. This door is thin over Doc 06's own machinery:
     * CreateTicket::create with previousTicketId carries the link (C13's
     * earlier-ticket line renders it), the FR-10 claim inside create() is the
     * one-open-per-conversation verdict (open_ticket_exists, mapped with the
     * existing ticket for §8's jump link), and ticket.created is audited by
     * the service itself. Closed-only: a live ticket's recourse is never a
     * follow-up. Window state is deliberately NOT checked here — FR-12
     * creation has no window law; only reopen does (ReopenTicket refuses it).
     */
    public function followUp(Request $request, string $ticketId, \App\Domain\Tickets\Creation\CreateTicket $creator): JsonResponse
    {
        return $this->door(
            $request,
            $ticketId,
            rules: ['subject' => ['sometimes', 'nullable', 'string', 'max:120']],
            operation: function (Ticket $ticket, User $user, array $input) use ($creator): array {
                if ($ticket->getAttribute('status') !== 'closed') {
                    throw new DomainException('a follow-up ticket is the recourse for a CLOSED ticket (FR-08/§4.2); this one is not closed.');
                }

                /** @var \App\Models\Conversation $conversation */
                $conversation = \App\Models\Conversation::query()->findOrFail($ticket->getAttribute('conversation_id'));

                /** @var array{subject?: string|null} $input */
                try {
                    $minted = $creator->create(new \App\Domain\Tickets\Creation\NewTicket(
                        conversation: $conversation,
                        source: \App\Domain\Tickets\Creation\TicketSource::Agent,
                        actor: $user,
                        subject: $input['subject'] ?? null,
                        previousTicketId: $ticket->identifier(),
                    ));
                } catch (\App\Domain\Conversations\Storage\OpenTicketExists $exists) {
                    throw new DomainException(\App\Domain\Conversations\Storage\OpenTicketExists::CODE.': an open ticket already exists on this conversation (#'.$exists->existingTicketId.')');
                }

                $this->projector->recomputeTicket($minted->identifier());

                return ['ticket_id' => $minted->identifier(), 'number' => $minted->getAttribute('number')];
            },
        );
    }

    /**
     * The shared door shape: validate (C-04, the step-13 web-group JSON
     * pattern) → scope-resolve (C-10) → the owning service → recompute →
     * C-04 refusal mapping.
     *
     * @param  array<string, list<string>>  $rules
     * @param  callable(Ticket, User, array<string, mixed>): array<string, mixed>  $operation
     * @param  null|callable(Ticket): list<string>  $alsoRecompute  extra ticket ids to converge (read BEFORE the operation)
     */
    private function door(Request $request, string $ticketId, array $rules, callable $operation, ?callable $alsoRecompute = null): JsonResponse
    {
        try {
            /** @var array<string, mixed> $input */
            $input = $request->validate($rules);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }

        /** @var User $user */
        $user = $request->user();
        if ($this->resolveScoped($user, $ticketId) === null) {
            return $this->notFound();
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->find($ticketId);
        if ($ticket === null) {
            return $this->notFound();
        }

        $extra = $alsoRecompute !== null ? $alsoRecompute($ticket) : [];

        try {
            $payload = $operation($ticket, $user, $input);
        } catch (TransitionRefused $refused) {
            return ProblemDetails::response(ErrorCode::TransitionRejected, 422, $refused->getMessage(), extra: ['refusal_code' => $refused->refusalCode]);
        } catch (DomainException|InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::TransitionRejected, 422, $e->getMessage());
        }

        foreach ([$ticketId, ...$extra] as $id) {
            $this->projector->recomputeTicket($id);
        }

        return new JsonResponse(['ok' => true, ...$payload]);
    }

    private function resolveScoped(User $user, string $ticketId): ?object
    {
        $query = TicketListItem::query()->where('ticket_id', $ticketId);
        $this->scopes->apply($query, 'tickets', $user, ListReadModel::columns());

        return $query->first();
    }

    private function notFound(): JsonResponse
    {
        Log::info('inbox operation 404-pattern served', ['metric' => 'security.cross_tenant_probe']);

        return ProblemDetails::response(
            ErrorCode::ResourceNotFound,
            404,
            'not found',
            extra: ['search_instead' => '/inbox'],
        );
    }
}
