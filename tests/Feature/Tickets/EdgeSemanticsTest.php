<?php

declare(strict_types=1);

use App\Domain\Tickets\Timers\TicketTimers;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| Doc 06 EC-07 / EC-08 / EC-10 — the remaining edge semantics (step 8)
|--------------------------------------------------------------------------
|
| EC-07  "first commit wins; the second's identical-target transition returns
|        success-no-op (documented idempotency)" — both halves: the same-state call
|        and the raced guarded write.
| EC-08  "automation resolves while an agent is typing" — the PROMPT is Doc 07's UI;
|        the server rules it composes from are (a) 'send and reopen': the staff
|        resolved→open door under tickets.reopen, and (b) the FR-08 family: a
|        customer reply reopens through the consumer (proven at step 5). Both doors
|        exist and neither loses the agent's draft — drafts are Doc 04's, untouched
|        by transitions.
| EC-10  "pending_customer when the Doc 05 reply window expires → status unchanged
|        (orthogonal) … BR-07 governs" — the flag changes ANSWERS (automated
|        outcomes refused), never STATE.
*/

// ── EC-07: documented idempotency, both halves ───────────────────────────────

test('EC-07: two agents resolving at once — the second identical-target call is a SUCCESS no-op with no second event', function () {
    [, , $ticket] = ticketFixture('open');
    $service = app(TicketTransitionService::class);
    $agentA = ticketActor(['tickets.status.change', 'tickets.view'], 'Fixture Racer A');
    $agentB = ticketActor(['tickets.status.change', 'tickets.view'], 'Fixture Racer B');

    $first = $service->transition($ticket, TicketStatus::Resolved, TransitionContext::staff($agentA));
    expect($first->changed)->toBeTrue();

    // Agent B pressed the same button on the same (now stale) screen.
    $second = $service->transition($ticket->fresh(), TicketStatus::Resolved, TransitionContext::staff($agentB));

    expect($second->changed)->toBeFalse('EC-07: the identical-target repeat is a success-no-op, not an error modal')
        ->and(DB::table('outbox')->where('name', 'ticket.resolved')->count())->toBe(1, 'a no-op must not re-emit')
        ->and(DB::table('outbox')->where('name', 'ticket.status_changed')->count())->toBe(1, 'the one real resolve only — the fixture inserts its row directly');
});

test('EC-07, the raced half: a transition validated against a STALE status lands on the guard — same target no-ops, different target re-evaluates', function () {
    [, , $ticket] = ticketFixture('open');
    $service = app(TicketTransitionService::class);

    // Two independently loaded models, both still saying `open`, while the world
    // is about to move to resolved.
    $staleSame = Ticket::query()->findOrFail($ticket->identifier());
    $staleOther = Ticket::query()->findOrFail($ticket->identifier());
    $service->transition($ticket, TicketStatus::Resolved, TransitionContext::system('the other agent won'));

    // Same target through the stale model: legality passes on the stale `open`, the
    // guarded write matches zero rows, the service re-reads and answers no-op.
    $result = $service->transition($staleSame, TicketStatus::Resolved, TransitionContext::system('the loser retries'));
    expect($result->changed)->toBeFalse();

    // A DIFFERENT target validated against the stale status is re-evaluated against
    // reality, not forced: open→pending_customer is legal on the stale read, but the
    // world says resolved, and resolved→pending_customer is not an edge.
    expect(fn () => $service->transition($staleOther, TicketStatus::PendingCustomer, TransitionContext::system('stale screen')))
        ->toThrow(App\Domain\Tickets\Transitions\IllegalTransition::class);
});

// ── EC-08: the server rules under the Doc 07 prompt ──────────────────────────

test('EC-08: after automation resolves mid-composition, "send and reopen" is the staff resolved→open door — the reopen counts and the timer disarms', function () {
    [$tenant, , $ticket] = ticketFixture('open');
    $member = $tenant->identifier().'|'.$ticket->identifier();
    Redis::connection('durable')->del(TicketTimers::ZSET);

    // "resolved by rule X": Doc 12's automation actor through the machine.
    app(TicketTransitionService::class)->transition($ticket, TicketStatus::Resolved, TransitionContext::system('automation rule fixture'));
    $ticket->refresh();
    expect($ticket->auto_close_at)->not->toBeNull('fixture: the automated resolve armed the timer')
        ->and(ticketTimerScore($member))->not->toBeNull();

    // The agent chooses "send and reopen": the reopen precedes the send, so the
    // reply lands on an OPEN ticket (the discard branch is a UI-only no-op).
    $agent = ticketActor(['tickets.reopen', 'tickets.view'], 'Fixture EC08 Agent');
    app(TicketTransitionService::class)->transition($ticket, TicketStatus::Open, TransitionContext::staff($agent));

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Open)
        ->and($ticket->reopened_count)->toBe(1, 'the send-and-reopen IS a reopen — FR-08 family')
        ->and($ticket->auto_close_at)->toBeNull('the armed timer must disarm with the reopen')
        ->and(ticketTimerScore($member))->toBeNull()
        ->and($ticket->resolved_at)->toBeNull('FR-24: reopen clears resolved_at until re-resolved');

    // The prompt itself ("resolved by rule X — send and reopen, or discard?") is
    // Doc 07's dialog over these doors; nothing further exists server-side.
});

// ── EC-10: the unreachable flag is ORTHOGONAL to status ──────────────────────

test('EC-10: channel_unreachable on a pending_customer ticket changes no status — BR-07 refuses the AUTOMATED outcomes, staff still decide', function () {
    [, , $ticket] = ticketFixture('open');
    $service = app(TicketTransitionService::class);
    $service->transition($ticket, TicketStatus::PendingCustomer, TransitionContext::system('ec10 fixture'));
    $ticket->refresh();

    // Doc 05's reply window expires: the flag flips, and NOTHING else moves.
    Ticket::query()->whereKey($ticket->identifier())->update(['channel_unreachable' => true]);
    $ticket->refresh();

    expect($ticket->status())->toBe(TicketStatus::PendingCustomer, 'EC-10: status unchanged — the flag is orthogonal')
        ->and(DB::table('outbox')->where('name', 'ticket.status_changed')->whereRaw("payload->>'ticket_id' = ?", [$ticket->identifier()])->count())
        ->toBe(1, 'the one fixture transition only — flipping the flag emits no transition');

    // BR-07: the AUTOMATED resolutions are refused while unreachable…
    $service->transition($ticket, TicketStatus::Open, TransitionContext::system('customer replied via another channel'));
    $ticket->refresh();
    expect(fn () => $service->transition($ticket, TicketStatus::Resolved, TransitionContext::system('automation trying to resolve')))
        ->toThrow(TransitionRefused::class, 'channel_unreachable_hold');

    // …and a HUMAN still decides outcomes: the staff resolve stands.
    $staff = ticketActor(['tickets.status.change', 'tickets.view'], 'Fixture EC10 Staff');
    $service->transition($ticket->fresh(), TicketStatus::Resolved, TransitionContext::staff($staff));
    expect($ticket->fresh()->status())->toBe(TicketStatus::Resolved, 'BR-07: humans decide outcomes for unreachable customers');
});
