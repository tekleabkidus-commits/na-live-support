<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Conversations\Drafts\DraftStore;
use App\Domain\Conversations\Notes\NotesWriter;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\Scopes\ScopeSets;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The composer's doors (S07 step 11).
 *
 * MODE SAFETY IS STRUCTURAL (FR-17/BR-02, N-04/N-09 posture): REPLY and
 * NOTE are SEPARATE ROUTES to SEPARATE SERVICES — ComposeOutbound REFUSES
 * kind=note in its own body (Doc 04 SEC-07), and NotesWriter has no
 * adapter path AT ALL. A confused client cannot turn a note into a send:
 * there is no parameter on the note door that reaches an adapter, and the
 * reply door cannot carry a note. Sabotage #14 tries to route a note
 * toward the adapter path and the Doc 04 AC-04 integration proof goes red.
 *
 * FR-21 (the split button): reply THEN transition, atomically-IN-ORDER —
 * the reply STAYS SENT when the transition is refused; the refusal comes
 * back as transition_rejected with the reply id so the client renders the
 * rollback message + B12-A01 AC-05's one-tap retry (the /transition door).
 *
 * Doc 06 EC-08 (the closing row's prompt): replying on a RESOLVED ticket
 * requires confirm_reopen — without it the door answers 409
 * reopen_confirmation_required (the dialog's shape); with it the ticket
 * reopens through the SOLE writer FIRST, then the reply sends.
 */
final class ComposerController extends Controller
{
    public function __construct(
        private readonly ScopeSets $scopes,
        private readonly ComposeOutbound $compose,
        private readonly NotesWriter $notes,
        private readonly DraftStore $drafts,
        private readonly TicketTransitionService $transitions,
        private readonly ListProjector $projector,
        private readonly \App\Domain\Tickets\Assignment\AssignTicket $assign,
        // COUNTERSIGNED CORE EDIT (S09 step 10 packet): Doc 09's send-time seam —
        // FR-15's original-in-metadata + FR-16's verdict ride the agent's send.
        private readonly \App\Domain\Ai\Copilot\CopilotSendMeta $copilot,
    ) {}

    public function reply(Request $request, string $conversationId): JsonResponse
    {
        /** @var array{body: string, idempotency_key?: string, then?: string, confirm_reopen?: bool, ai_artifact_id?: string} $input */
        $input = $request->validate([
            'body' => ['required', 'string', 'max:16384'],
            'idempotency_key' => ['sometimes', 'string', 'max:64'],
            'then' => ['sometimes', 'string', 'in:resolve,pending_customer'],
            'confirm_reopen' => ['sometimes', 'boolean'],
            // Doc 09 FR-15/FR-16 (countersigned packet): the Copilot artifact this body came from.
            'ai_artifact_id' => ['sometimes', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $conversation = $this->resolveScoped($user, $conversationId);
        if ($conversation === null) {
            return $this->notFound();
        }

        $ticket = $this->openOrLatestTicket($conversationId);

        // Doc 06 EC-08: a reply on a RESOLVED ticket prompts; confirmed →
        // the resolved→open door (the sole writer) BEFORE the send.
        if ($ticket !== null && $ticket->getAttribute('status') === 'resolved') {
            if (($input['confirm_reopen'] ?? false) !== true) {
                return ProblemDetails::response(ErrorCode::ReopenConfirmationRequired, 409, 'the ticket is resolved — confirm to reopen and send');
            }
            try {
                $this->transitions->transition($ticket, TicketStatus::Open, TransitionContext::staff($user, 'reply on resolved (Doc 06 EC-08)'));
            } catch (\App\Domain\Tickets\Transitions\TransitionNotPermitted|\App\Domain\Tickets\Transitions\IllegalTransition|DomainException|\LogicException $e) {
                return ProblemDetails::response(ErrorCode::TransitionRejected, 422, $e->getMessage());
            }
            $this->projector->recomputeTicket($ticket->identifier());
        }

        // Doc 09 FR-15 (countersigned packet): a translate artifact carries its
        // ORIGINAL into the message's channel_meta at compose time — one write,
        // same row; FR-16's send-time verdict rides the same call. Doc 09 owns
        // the mapping: an unknown / foreign / non-insertable artifact, or a
        // caller without ai.copilot.insert_draft, yields [] and the reply
        // proceeds exactly as without the parameter.
        $channelMeta = isset($input['ai_artifact_id'])
            ? $this->copilot->channelMetaForSend($user, $input['ai_artifact_id'], $conversation->identifier(), $input['body'])
            : [];

        try {
            $message = $this->compose->compose($conversation, 'agent', $user->identifier(), 'text', $input['body'], $input['idempotency_key'] ?? null, $channelMeta);
        } catch (DomainException $e) {
            return ProblemDetails::response(ErrorCode::ConversationBlocked, 422, $e->getMessage());
        }

        // FR-22: a successful send clears the sender's draft (tombstone).
        $this->drafts->clearOnSend($user, $conversation);

        // Doc 09 FR-16 (countersigned packet): the verdict against the message
        // that actually went out — after the row exists, never before.
        if (isset($input['ai_artifact_id'])) {
            $this->copilot->recordSent($user, $input['ai_artifact_id'], $message->identifier());
        }

        // Doc 06 FR-05/BR-03 (ruling (c), step 14): the first staff public
        // reply IS a claim — through the owning service's own door, honoring
        // tickets.implicit_claim; a lost race or a scope refusal leaves the
        // reply standing and only the claim declined (the service's
        // contract). The claim's ticket.assigned event + audit ride the
        // service's write path, same as every other assignment.
        $implicitClaim = false;
        if ($ticket !== null) {
            $implicitClaim = $this->assign->implicitClaimOnReply($ticket->refresh(), $user);
            if ($implicitClaim) {
                $this->projector->recomputeTicket($ticket->identifier());
            }
        }

        // FR-21: the transition half, IN ORDER, after the send — a refusal
        // leaves the reply SENT and reports for the one-tap retry.
        $transition = null;
        if (isset($input['then']) && $ticket !== null) {
            $to = $input['then'] === 'resolve' ? TicketStatus::Resolved : TicketStatus::PendingCustomer;
            try {
                $this->transitions->transition($ticket->refresh(), $to, TransitionContext::staff($user, 'send-and-set-status (FR-21)'));
                $this->projector->recomputeTicket($ticket->identifier());
                $transition = ['ok' => true, 'to' => $to->value];
            } catch (\App\Domain\Tickets\Transitions\TransitionNotPermitted|\App\Domain\Tickets\Transitions\IllegalTransition|DomainException|\LogicException $e) {
                $transition = ['ok' => false, 'to' => $to->value, 'code' => 'transition_rejected', 'detail' => $e->getMessage()];
            }
        }

        return new JsonResponse([
            'message_id' => $message->identifier(),
            'delivery_state' => $message->getAttribute('delivery_state'),
            'transition' => $transition,
            // BR-01: the claim that rode this reply is visible, not implied.
            'implicit_claim' => $implicitClaim,
        ], 201);
    }

    /** The NOTE door — no adapter path exists beyond it (N-04, structural). */
    public function note(Request $request, string $conversationId): JsonResponse
    {
        /** @var array{body: string} $input */
        $input = $request->validate(['body' => ['required', 'string', 'max:16384']]);

        /** @var User $user */
        $user = $request->user();
        $conversation = $this->resolveScoped($user, $conversationId);
        if ($conversation === null) {
            return $this->notFound();
        }

        $note = $this->notes->addNote($conversation, $user, $input['body']);

        return new JsonResponse(['message_id' => $note->identifier()], 201);
    }

    /**
     * FR-22 drafts, the READ half — DraftStore's own restore contract ("the
     * row simply persists"); the composer hydrates from here on thread open.
     * EC-12's executed form (a draft SURVIVING a merge, B12-A01 AC-08)
     * surfaced that nothing ever called it.
     */
    public function draftShow(Request $request, string $conversationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $conversation = $this->resolveScoped($user, $conversationId);
        if ($conversation === null) {
            return $this->notFound();
        }

        $draft = $this->drafts->for($user, $conversation);
        $updatedAt = $draft?->getAttribute('updated_at');

        return new JsonResponse([
            'body' => $draft?->getAttribute('body'),
            'updated_at' => $updatedAt instanceof \DateTimeInterface
                ? CarbonImmutable::instance($updatedAt)->toIso8601String()
                : null,
        ]);
    }

    /** FR-22 drafts: LWW with the Doc 04 EC-06 conflict signal. */
    public function draft(Request $request, string $conversationId): JsonResponse
    {
        /** @var array{body: string|null, expected_updated_at?: string|null} $input */
        $input = $request->validate([
            'body' => ['present', 'nullable', 'string', 'max:16384'],
            'expected_updated_at' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $conversation = $this->resolveScoped($user, $conversationId);
        if ($conversation === null) {
            return $this->notFound();
        }

        $expectedRaw = $input['expected_updated_at'] ?? null;
        $expected = is_string($expectedRaw) ? CarbonImmutable::parse($expectedRaw) : null;

        return new JsonResponse($this->drafts->save($user, $conversation, $input['body'], $expected));
    }

    /**
     * B12-A01 AC-05's one-tap retry: the TRANSITION alone, through the sole
     * writer — the reply already sent and stays sent.
     */
    public function transition(Request $request, string $conversationId): JsonResponse
    {
        /** @var array{to: string, pending_reason?: string} $input */
        $input = $request->validate([
            'to' => ['required', 'string', 'in:resolve,pending_customer,pending_internal,open'],
            // Doc 06 FR-01's enum, ridden on the transition (§4.2); the
            // service asserts the reason↔state pairing, the DB CHECK backs it.
            'pending_reason' => ['sometimes', 'string', 'in:awaiting_customer,awaiting_internal:department,awaiting_internal:named_user,awaiting_internal:external'],
        ]);

        /** @var User $user */
        $user = $request->user();
        if ($this->resolveScoped($user, $conversationId) === null) {
            return $this->notFound();
        }
        $ticket = $this->openOrLatestTicket($conversationId);
        if ($ticket === null) {
            return $this->notFound();
        }

        $to = match ($input['to']) {
            'resolve' => TicketStatus::Resolved,
            'pending_customer' => TicketStatus::PendingCustomer,
            'pending_internal' => TicketStatus::PendingInternal,
            default => TicketStatus::Open,
        };

        // The closed→open edge demands a REAL written reason (the matrix's
        // own rule) plus the reopen window check — both live behind the
        // dedicated reopen door (TicketOperationsController::reopen, over
        // ReopenTicket::reopenByStaff). Refusing here fixes the defect the
        // step-14 sweep found: this door used to satisfy the mandatory-reason
        // check with its own hardcoded origin string.
        if ($to === TicketStatus::Open && $ticket->getAttribute('status') === 'closed') {
            return ProblemDetails::response(
                ErrorCode::TransitionRejected,
                422,
                'reopening a closed ticket needs a written reason — use the reopen door (FR-08).',
                extra: ['refusal_code' => 'reopen_reason_required'],
            );
        }

        try {
            $context = TransitionContext::staff($user, 'composer transition (FR-21 retry)')
                ->withPendingReason($input['pending_reason'] ?? null);
            $result = $this->transitions->transition($ticket, $to, $context);
            $this->projector->recomputeTicket($ticket->identifier());
        } catch (\App\Domain\Tickets\Transitions\TransitionNotPermitted|\App\Domain\Tickets\Transitions\IllegalTransition|DomainException|\LogicException $e) {
            return ProblemDetails::response(ErrorCode::TransitionRejected, 422, $e->getMessage());
        }

        // D8 (EC-07's surface): two agents agreeing is not a conflict — the
        // noop is a SUCCESS the client may say quietly. A-37: the CAUSAL
        // noop declaration — set here and only here, tied to the OWNING
        // service's own noop return, so the audit verifier skips exactly
        // the documented no-write path and nothing else.
        if (! $result->changed) {
            $request->attributes->set('audit.noop', true);
        }

        return new JsonResponse(['ok' => true, 'to' => $to->value, 'noop' => ! $result->changed]);
    }

    private function openOrLatestTicket(string $conversationId): ?Ticket
    {
        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()
            ->where('conversation_id', $conversationId)
            ->orderByRaw("CASE WHEN status IN ('new','open','pending_customer','pending_internal','escalated') THEN 0 WHEN status = 'resolved' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->first();

        return $ticket;
    }

    private function resolveScoped(User $user, string $conversationId): ?Conversation
    {
        $conversation = Conversation::query()->find($conversationId);
        if ($conversation === null) {
            return null;
        }
        $query = \App\Models\TicketListItem::query()->where('conversation_id', $conversationId);
        $this->scopes->apply($query, 'tickets', $user, ListReadModel::columns());

        return $query->exists() ? $conversation : null;
    }

    private function notFound(): JsonResponse
    {
        \Illuminate\Support\Facades\Log::info('inbox composer 404-pattern served', ['metric' => 'security.cross_tenant_probe']);

        return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found', extra: ['search_instead' => '/inbox']);
    }
}
