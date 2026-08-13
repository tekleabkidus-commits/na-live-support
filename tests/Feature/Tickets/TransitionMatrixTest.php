<?php

declare(strict_types=1);

use App\Domain\Tickets\Transitions\IllegalTransition;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionActor;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionMatrix;
use App\Domain\Tickets\Transitions\TransitionNotPermitted;
use App\Domain\Tickets\Transitions\TransitionRefused;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 06 AC-01 — the table-driven matrix, EVERY cell of the 8 × 8 machine
|--------------------------------------------------------------------------
|
| "AC-01 Table-driven matrix: all legal cells succeed with correct events/timestamps;
| all illegal cells return illegal_transition; same-state calls no-op successfully."
|
| The three clauses are three tests below, and between them they drive all 64 ordered
| pairs of the eight statuses — 15 legal edges, 41 illegal pairs, 8 same-state calls —
| with nothing enumerated by hand twice: the cells are GENERATED from the status enum,
| and legality is asked of the matrix. A future session that adds an edge to
| TransitionMatrix does not have to remember to add a test; the illegal-cell count in
| the last assertion moves and tells them.
|
| §21 required this file to exist before anything composed on the service, and it did:
| step 2 built the service and this test together, before any operation.
|
| WHY THE ACTOR IS SUPER-PRIVILEGED HERE. This file is about LEGALITY, so the actor
| holds every ticket key — a permission denial would mask the very thing under test.
| Permission behaviour has its own tests below and in MidActionPermissionExpiryTest.
*/

/** @return array{0: App\Models\Ticket, 1: App\Models\User} */
function matrixFixture(string $status): array
{
    [, , $ticket] = ticketFixture($status);
    $actor = ticketActor([
        'tickets.status.change', 'tickets.escalate', 'tickets.spam.mark',
        'tickets.reopen', 'tickets.close',
    ], 'Fixture Matrix Role');

    return [$ticket, $actor];
}

/** A reason long enough for every edge that demands one (FR-23's ≥ 10 is the strictest). */
const MATRIX_REASON = 'fixture reason, long enough for FR-23';

test('AC-01 clause 1: every legal cell succeeds, changing status and emitting ticket.status_changed', function () {
    $matrix = new TransitionMatrix;
    $service = app(TicketTransitionService::class);
    $proven = 0;

    foreach ($matrix->allEdges() as $edge) {
        /** @var TicketStatus $from */
        $from = $edge['from'];
        /** @var TicketStatus $to */
        $to = $edge['to'];

        [$ticket, $actor] = matrixFixture($from->value);
        $before = DB::table('outbox')->where('name', 'ticket.status_changed')->count();

        $result = $service->transition($ticket, $to, TransitionContext::staff($actor, MATRIX_REASON));

        expect($result->changed)->toBeTrue("{$from->value} -> {$to->value} reported no change");
        expect($result->ticket->status())->toBe($to, "{$from->value} -> {$to->value} did not persist");

        // The event carries FR-04's old/new — the thing every consumer reads.
        $event = DB::table('outbox')->where('name', 'ticket.status_changed')->orderByDesc('id')->first();
        expect(DB::table('outbox')->where('name', 'ticket.status_changed')->count())
            ->toBe($before + 1, "{$from->value} -> {$to->value} emitted no event");
        $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
        expect($payload['old_status'])->toBe($from->value)
            ->and($payload['new_status'])->toBe($to->value)
            ->and($payload['actor_type'])->toBe('staff');

        // FR-24 stamps ride the SAME statement (the R-20 trigger): a transition
        // always moves the ordering clock, and entering a terminal-ish state
        // stamps its own column.
        expect($result->ticket->last_status_changed_at)->not->toBeNull();
        if ($to === TicketStatus::Resolved) {
            expect($result->ticket->resolved_at)->not->toBeNull("resolved_at was not stamped on {$from->value} -> resolved");
        }
        if ($to === TicketStatus::Closed) {
            expect($result->ticket->closed_at)->not->toBeNull();
        }
        if ($to === TicketStatus::Spam) {
            expect($result->ticket->spam_marked_at)->not->toBeNull();
        }

        // SEC-03: actor + old/new, every transition.
        $audit = DB::table('audit_log')
            ->where('action_key', 'ticket.status_changed')
            ->where('entity_id', $ticket->identifier())
            ->first();
        expect($audit)->not->toBeNull("{$from->value} -> {$to->value} left no audit entry");
        expect($audit->actor_type)->toBe('staff');

        $proven++;
    }

    // The count is asserted so that DELETING an edge cannot quietly shrink this test.
    expect($proven)->toBe(15, 'the FR-04 machine has 15 edges read off §4.2; the matrix now reports a different number');
});

test('AC-01 clause 2: every illegal cell is refused with illegal_transition {from, to}', function () {
    $matrix = new TransitionMatrix;
    $service = app(TicketTransitionService::class);
    $refused = 0;

    foreach (TicketStatus::cases() as $from) {
        foreach (TicketStatus::cases() as $to) {
            if ($from === $to || $matrix->isLegal($from, $to)) {
                continue;   // clauses 1 and 3 own those cells
            }

            [$ticket, $actor] = matrixFixture($from->value);

            try {
                $service->transition($ticket, $to, TransitionContext::staff($actor, MATRIX_REASON));
                throw new RuntimeException("{$from->value} -> {$to->value} was ACCEPTED — FR-04 says the edges are exactly the diagram's");
            } catch (IllegalTransition $e) {
                expect($e->from)->toBe($from)
                    ->and($e->to)->toBe($to)
                    ->and(IllegalTransition::CODE)->toBe('illegal_transition');
            }

            // Nothing moved, nothing was emitted.
            $ticket->refresh();
            expect($ticket->status())->toBe($from, "a refused {$from->value} -> {$to->value} still changed the row");
            $refused++;
        }
    }

    expect($refused)->toBe(41, '64 ordered pairs = 15 legal + 8 same-state + 41 illegal; that arithmetic no longer holds');
});

test('AC-01 clause 3: a same-state call no-ops SUCCESSFULLY — no error, no event, no audit entry', function () {
    $service = app(TicketTransitionService::class);

    foreach (TicketStatus::cases() as $status) {
        [$ticket, $actor] = matrixFixture($status->value);
        $eventsBefore = DB::table('outbox')->where('name', 'ticket.status_changed')->count();
        $auditBefore = DB::table('audit_log')->where('action_key', 'ticket.status_changed')->count();

        // EC-07's documented idempotency: two agents resolve at once and the second
        // one gets a success, not an error-modal storm.
        $result = $service->transition($ticket, $status, TransitionContext::staff($actor, MATRIX_REASON));

        expect($result->changed)->toBeFalse("{$status->value} -> {$status->value} reported a change")
            ->and($result->ticket->status())->toBe($status)
            ->and(DB::table('outbox')->where('name', 'ticket.status_changed')->count())->toBe($eventsBefore)
            ->and(DB::table('audit_log')->where('action_key', 'ticket.status_changed')->count())->toBe($auditBefore);
    }
});

// ── The pipeline's other stages, on the machine they guard ───────────────────

test('FR-04: a permission the actor lacks refuses the transition, naming the key', function () {
    [, , $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.view'], 'Fixture View Only Role');

    expect(fn () => app(TicketTransitionService::class)->transition(
        $ticket,
        TicketStatus::Escalated,
        TransitionContext::staff($actor, MATRIX_REASON),
    ))->toThrow(TransitionNotPermitted::class, 'tickets.escalate');

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Open);
});

test('FR-04/FR-23/BR-06: escalation demands a reason of at least 10 characters', function () {
    $service = app(TicketTransitionService::class);

    [, , $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.escalate'], 'Fixture Escalate Role');

    // Absent entirely.
    expect(fn () => $service->transition($ticket, TicketStatus::Escalated, TransitionContext::staff($actor)))
        ->toThrow(TransitionRefused::class, 'reason_required');

    // Present but not articulate (BR-06: reportable and coachable, never a silent shove).
    expect(fn () => $service->transition($ticket, TicketStatus::Escalated, TransitionContext::staff($actor, 'too short')))
        ->toThrow(TransitionRefused::class, 'reason_too_short');

    // Ten or more characters passes.
    expect($service->transition($ticket, TicketStatus::Escalated, TransitionContext::staff($actor, 'card blocked mid-withdrawal'))->changed)
        ->toBeTrue();
});

test('FR-04: staff reopen of a closed ticket needs a reason, and spam marking needs one too', function () {
    $service = app(TicketTransitionService::class);

    [, , $closed] = ticketFixture('closed');
    $reopener = ticketActor(['tickets.reopen'], 'Fixture Reopen Role');
    expect(fn () => $service->transition($closed, TicketStatus::Open, TransitionContext::staff($reopener)))
        ->toThrow(TransitionRefused::class, 'reason_required');

    [, , $open] = ticketFixture('open');
    $marker = ticketActor(['tickets.spam.mark'], 'Fixture Spam Role');
    expect(fn () => $service->transition($open, TicketStatus::Spam, TransitionContext::staff($marker)))
        ->toThrow(TransitionRefused::class, 'reason_required');
});

test('BR-07: an unreachable customer is never AUTO-resolved or auto-closed, but staff may still decide', function () {
    $service = app(TicketTransitionService::class);

    // The automated path is barred — "humans decide outcomes for unreachable customers".
    [, , $ticket] = ticketFixture('open', ['channel_unreachable' => true]);
    expect(fn () => $service->transition($ticket, TicketStatus::Resolved, TransitionContext::system()))
        ->toThrow(TransitionRefused::class, 'channel_unreachable_hold');

    [, , $resolved] = ticketFixture('resolved', ['channel_unreachable' => true]);
    expect(fn () => $service->transition($resolved, TicketStatus::Closed, TransitionContext::system()))
        ->toThrow(TransitionRefused::class, 'channel_unreachable_hold');

    // A person deliberately resolving is NOT barred: BR-07 restrains automation, not judgement.
    [, , $human] = ticketFixture('open', ['channel_unreachable' => true]);
    $actor = ticketActor(['tickets.status.change'], 'Fixture Status Role');
    expect($service->transition($human, TicketStatus::Resolved, TransitionContext::staff($actor))->changed)->toBeTrue();
});

test('FR-04: system, AI and automation actors transition without a user and are recorded as themselves', function () {
    $service = app(TicketTransitionService::class);

    foreach ([TransitionActor::System, TransitionActor::Ai, TransitionActor::Automation] as $actor) {
        [, , $ticket] = ticketFixture('resolved');

        $result = $service->transition($ticket, TicketStatus::Open, new TransitionContext($actor));

        expect($result->changed)->toBeTrue();

        $event = DB::table('outbox')->where('name', 'ticket.status_changed')->orderByDesc('id')->first();
        $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
        expect($payload['actor_type'])->toBe($actor->value)
            ->and($payload['actor_id'])->toBeNull();
    }
});

test('FR-10: leaving and entering the open family moves Doc 04\'s open-ticket slot with it', function () {
    $service = app(TicketTransitionService::class);
    [, $conversation, $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.status.change', 'tickets.reopen'], 'Fixture Slot Role');

    expect($conversation->fresh()->open_ticket_id)->toBe($ticket->identifier());

    // resolved is NOT open-family (FR-10) — the slot is released.
    $service->transition($ticket, TicketStatus::Resolved, TransitionContext::staff($actor));
    expect($conversation->fresh()->open_ticket_id)->toBeNull();

    // …and reclaimed on the way back, which is what makes a second ticket impossible.
    $service->transition($ticket->fresh(), TicketStatus::Open, TransitionContext::system());
    expect($conversation->fresh()->open_ticket_id)->toBe($ticket->identifier());
});

test('SS-04: the matrix is data, so the legal-target list a UI renders comes from the same table', function () {
    $matrix = new TransitionMatrix;

    // §8: "status control rendering only legal next-states (illegal targets absent,
    // not disabled)" — the panel reads this, so it can never offer a refused edge.
    expect($matrix->legalTargets(TicketStatus::Open))
        ->toBe([TicketStatus::PendingCustomer, TicketStatus::PendingInternal, TicketStatus::Escalated, TicketStatus::Resolved, TicketStatus::Spam])
        ->and($matrix->legalTargets(TicketStatus::Spam))
        ->toBe([TicketStatus::New, TicketStatus::Open])
        ->and($matrix->legalTargets(TicketStatus::PendingCustomer))
        ->toBe([TicketStatus::Open]);
});

test('FR-01/§4.2 (S07 step 14): the pending reason RIDES THE TRANSITION — written in the same statement entering pending, refused on a mismatched pairing before the transaction, cleared in the same statement leaving the pending family', function () {
    $service = app(TicketTransitionService::class);

    // Entering pending_customer with its one legal reason: atomic with the status;
    // leaving the pending family clears it in the transition's own write.
    [$ticket, $actor] = matrixFixture('open');
    $service->transition($ticket, TicketStatus::PendingCustomer,
        TransitionContext::staff($actor)->withPendingReason('awaiting_customer'));
    expect($ticket->fresh()->pending_reason)->toBe('awaiting_customer');
    $service->transition($ticket->fresh(), TicketStatus::Open,
        TransitionContext::staff($actor));
    expect($ticket->fresh()->pending_reason)->toBeNull();

    // pending_internal takes only the awaiting_internal:* values.
    [$internal, $internalActor] = matrixFixture('open');
    $service->transition($internal, TicketStatus::PendingInternal,
        TransitionContext::staff($internalActor)->withPendingReason('awaiting_internal:department'));
    expect($internal->fresh()->pending_reason)->toBe('awaiting_internal:department');

    // A mismatched pairing is refused BEFORE anything is written (C-04 stable message).
    [$mismatched, $mismatchedActor] = matrixFixture('open');
    expect(fn () => $service->transition($mismatched, TicketStatus::PendingCustomer,
        TransitionContext::staff($mismatchedActor)->withPendingReason('awaiting_internal:department')))
        ->toThrow(DomainException::class, 'pending_reason_mismatch');
    expect($mismatched->fresh()->status)->toBe('open')
        ->and($mismatched->fresh()->pending_reason)->toBeNull();

    // A reason on a NON-pending transition is refused the same way.
    [$wrongTarget, $wrongActor] = matrixFixture('open');
    expect(fn () => $service->transition($wrongTarget, TicketStatus::Resolved,
        TransitionContext::staff($wrongActor)->withPendingReason('awaiting_customer')))
        ->toThrow(DomainException::class, 'pending_reason_mismatch');
    expect($wrongTarget->fresh()->status)->toBe('open');
});
