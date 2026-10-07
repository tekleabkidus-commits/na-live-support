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
        private readonly \App\Domain\Tickets\Creation\SubjectWriter $subjects,
        private readonly \App\Domain\Tickets\Followers\TicketFollowers $followerService,
        private readonly ChangePriority $priority,
        private readonly TicketTags $tags,
        private readonly ListProjector $projector,
        private readonly ScopeSets $scopes,
    ) {}

    /**
     * B2-S03/B14 (step 14): the followers row — state read + the self
     * follow/unfollow toggle through the owning TicketFollowers service
     * (tickets.follow is the service's own gate; FR-18).
     */
    public function followers(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ticket = $this->resolveScoped($user, $ticketId);
        if ($ticket === null) {
            return $this->notFound();
        }

        $ids = $this->followerService->followerIds($ticket);
        /** @var array<string, string> $names */
        $names = $ids === []
            ? []
            : \Illuminate\Support\Facades\DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')->all();

        return new JsonResponse([
            'followers' => array_map(
                fn (string $id): array => ['id' => $id, 'name' => $names[$id] ?? ''],
                $ids,
            ),
            'following' => in_array($user->identifier(), $ids, true),
        ]);
    }

    public function follow(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ticket = $this->resolveScoped($user, $ticketId);
        if ($ticket === null) {
            return $this->notFound();
        }

        try {
            $added = $this->followerService->follow($ticket, $user);
        } catch (\DomainException $e) {
            return ProblemDetails::response(ErrorCode::Forbidden, 403, $e->getMessage());
        }
        if (! $added) {
            // Already following — the service's documented noop (A-37).
            $request->attributes->set('audit.noop', true);
        }

        return new JsonResponse(['ok' => true, 'following' => true]);
    }

    public function unfollow(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ticket = $this->resolveScoped($user, $ticketId);
        if ($ticket === null) {
            return $this->notFound();
        }

        $removed = $this->followerService->unfollow($ticket, $user);
        if (! $removed) {
            // A-37's shape: not-following → nothing removed, nothing audited
            // — a documented noop the declaration must not punish.
            $request->attributes->set('audit.noop', true);
        }

        return new JsonResponse(['ok' => true, 'following' => false]);
    }

    /**
     * B2-S03/B13 (step 14): the subject door — FR-03's staff edit through
     * the owning SubjectWriter (tickets.edit is the service's own gate; the
     * route middleware repeats it for the mapping gate). The 120-char cap is
     * the drawn counter's own number; a blank subject is refused (Doc 09
     * fills absent subjects, staff never blank them).
     */
    public function subject(Request $request, string $ticketId): JsonResponse
    {
        try {
            /** @var array{subject: string} $input */
            $input = $request->validate(['subject' => ['required', 'string', 'max:120']]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // The filed step-13 platform item: web-group validate() renders
            // the HTML redirect for JSON callers — the per-door interim
            // pattern applies here as on every sibling door.
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

        try {
            $this->subjects->editedByStaff($ticket, $input['subject'], $user);
        } catch (\DomainException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }
        $this->projector->recomputeTicket($ticket->identifier());

        $ticket->refresh();

        return new JsonResponse([
            'subject' => $ticket->subject,
            'subject_source' => $ticket->getAttribute('subject_source'),
        ]);
    }

    /**
     * B2-S03/B12 (step 14): the ticket TIMELINE — the relayed events log,
     * newest first, over the now-living relay (the step-14 discovery's first
     * consumer). Actor law from the frame: HUMANS resolve to id+name;
     * automated events carry their CLASS and never a person's name. Payload
     * fields pass a curated allowlist — events carry ids and enums, and the
     * one free-text field the frame renders (the escalation reason) is
     * staff-authored, tenant-scoped content.
     */
    public function timeline(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ticket = $this->resolveScoped($user, $ticketId);
        if ($ticket === null) {
            return $this->notFound();
        }

        $all = $request->query('all') === '1';
        // P-TIMELINE-SMS (Doc 14 FR-12 / AC-09, countersigned): the eight sms.* lifecycle
        // events carrying this ticket_id are timeline rows too (Doc 06's "SMS sends related
        // to a ticket appear as system events with live state"). An explicit name list —
        // never `sms.%` — so the opt-out/health/broadcast/cost events stay off the timeline.
        $smsLifecycle = ['sms.queued', 'sms.held', 'sms.sent', 'sms.delivered', 'sms.undelivered', 'sms.failed', 'sms.skipped', 'sms.expired'];
        $base = \Illuminate\Support\Facades\DB::table('events')
            ->where(fn (\Illuminate\Database\Query\Builder $q) => $q->where('name', 'like', 'ticket.%')->orWhereIn('name', $smsLifecycle))
            ->where('payload->ticket_id', $ticket->identifier());
        $total = (clone $base)->count();
        /** @var list<object{id: string, name: string, payload: string, occurred_at: string}> $rows */
        $rows = $base->orderByDesc('occurred_at')->orderByDesc('id')
            ->when(! $all, fn ($q) => $q->limit(6))
            ->get(['id', 'name', 'payload', 'occurred_at'])->all();

        // S11 step 11b (STOP-G, the packet's named lines): ticket.routed carries record_id (the
        // expandable fetches the record by id through its own SEC-02 door), ticket.waiting its queue/position.
        // P-TIMELINE-SMS: the SMS keys are ids and enums (SEC-02 — never a phone, never a body).
        $detailKeys = ['old_status', 'new_status', 'old_assignee_id', 'new_assignee_id', 'by', 'old_priority', 'new_priority', 'subject_source', 'source', 'reason', 'path', 'record_id', 'queue', 'position', 'sms_message_id', 'event', 'lane', 'template_version_id'];
        $decoded = array_map(function (object $row) use ($detailKeys): array {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, 8, JSON_THROW_ON_ERROR);

            return [
                'id' => $row->id,
                'name' => $row->name,
                'occurred_at' => $row->occurred_at,
                'actor_type' => is_string($payload['actor_type'] ?? null) ? $payload['actor_type'] : 'system',
                'actor_id' => is_string($payload['actor_id'] ?? null) ? $payload['actor_id'] : (is_string($payload['by'] ?? null) ? $payload['by'] : null),
                'detail' => array_intersect_key($payload, array_flip($detailKeys)),
            ];
        }, $rows);

        // One name pass for every human id on the page (actors + assignees).
        $ids = [];
        foreach ($decoded as $row) {
            foreach ([$row['actor_id'], $row['detail']['old_assignee_id'] ?? null, $row['detail']['new_assignee_id'] ?? null] as $id) {
                if (is_string($id)) {
                    $ids[$id] = true;
                }
            }
        }
        /** @var array<string, string> $names */
        $names = $ids === []
            ? []
            : \Illuminate\Support\Facades\DB::table('users')->whereIn('id', array_keys($ids))->pluck('name', 'id')->all();

        // P-TIMELINE-SMS: the row's LIVE state and the DRAWN masked phone (+251 91 ••• 4417),
        // read from sms_messages by id through Doc 14's timeline facts door — so a later DLR
        // updates the chip (US-02 "delivered turns green") and the full number never crosses.
        $smsIds = [];
        foreach ($decoded as $row) {
            if (is_string($row['detail']['sms_message_id'] ?? null)) {
                $smsIds[] = $row['detail']['sms_message_id'];
            }
        }
        /** @var array<string, array{state: string, phone_masked: string, event: string, lane: string}> $smsFacts */
        $smsFacts = $smsIds === [] ? [] : app(\App\Domain\Sms\Timeline\SmsTimelineFacts::class)->forMessageIds($smsIds);

        return new JsonResponse([
            'total' => $total,
            'rows' => array_map(function (array $row) use ($names, $smsFacts): array {
                $detail = $row['detail'];
                if (is_string($detail['sms_message_id'] ?? null) && isset($smsFacts[$detail['sms_message_id']])) {
                    $detail['sms'] = $smsFacts[$detail['sms_message_id']];
                }
                $detail['old_assignee_name'] = is_string($detail['old_assignee_id'] ?? null) ? ($names[$detail['old_assignee_id']] ?? null) : null;
                $detail['new_assignee_name'] = is_string($detail['new_assignee_id'] ?? null) ? ($names[$detail['new_assignee_id']] ?? null) : null;
                unset($detail['old_assignee_id'], $detail['new_assignee_id']);

                return [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'occurred_at' => $row['occurred_at'],
                    'actor_type' => $row['actor_type'],
                    // The frame's law: a HUMAN actor names itself; an
                    // automated one names its class, never a person.
                    'actor_name' => in_array($row['actor_type'], ['agent', 'staff'], true) && is_string($row['actor_id'])
                        ? ($names[$row['actor_id']] ?? null)
                        : null,
                    'detail' => $detail,
                ];
            }, $decoded),
        ]);
    }

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
