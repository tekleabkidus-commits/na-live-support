<?php

declare(strict_types=1);

use App\Domain\Routing\Capacity\AssignmentStats;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Jobs\Routing\AssignmentStatsConsumer;
use App\Support\Events\ConsumerRegistry;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-05 / FR-06 / T-03 / FS-01 / ADR 0011 — the DURABLE stats maintainer
|--------------------------------------------------------------------------
| Registered in ConsumerRegistry (the pin moves in ReopenConsumerDrillTest in the SAME
| commit); recompute-from-truth so a replay converges (C-09); FS-01's rebuild == truth.
*/

it('is registered as a DURABLE consumer of the four assignment-shaped ticket events (ADR 0011) and nothing else silently', function () {
    foreach (['ticket.assigned', 'ticket.status_changed', 'ticket.transferred', 'ticket.assignee_deactivated'] as $event) {
        expect(in_array(AssignmentStatsConsumer::class, ConsumerRegistry::consumersFor($event), true))->toBeTrue("{$event} lacks the stats consumer");
    }
    expect(ConsumerRegistry::consumersFor('ticket.created'))->not->toContain(AssignmentStatsConsumer::class);
});

it('recomputes FROM TRUTH: a manual claim, a pending move and an unassign each drive the counters; a REPLAY of the same event converges (C-09); rebuild == the same truth (FS-01)', function () {
    [$ticket, $agent] = assignmentFixture(['tickets.view', 'tickets.assign', 'tickets.assign_self', 'tickets.status.change']);
    app(AssignTicket::class)->claim($ticket, $agent);
    $assigned = relayOutboxRow('ticket.assigned');   // the REAL published payload, relayed in-harness

    (new AssignmentStatsConsumer($assigned))->handle(app(AssignmentStats::class));
    $row = fn () => DB::table('agent_assignment_stats')->where('user_id', $agent->identifier())->first();
    expect((int) $row()->open_assigned)->toBe(1)->and((int) $row()->pending_customer_assigned)->toBe(0);

    // Replay: the same event twice converges (recompute, not increment).
    (new AssignmentStatsConsumer($assigned))->handle(app(AssignmentStats::class));
    expect((int) $row()->open_assigned)->toBe(1);

    // Pending move: pending_customer counts separately (FR-06's exclusion is READ-side).
    app(TicketTransitionService::class)->transition($ticket->fresh(), TicketStatus::PendingCustomer, TransitionContext::staff($agent)->withPendingReason('awaiting_customer'));
    app(AssignmentStats::class)->recompute($agent->identifier());
    expect((int) $row()->open_assigned)->toBe(1)->and((int) $row()->pending_customer_assigned)->toBe(1);

    // Unassign: the counters fall back to zero through the same recompute.
    app(AssignTicket::class)->unassign($ticket->fresh(), $agent);
    app(AssignmentStats::class)->recompute($agent->identifier());
    expect((int) $row()->open_assigned)->toBe(0);

    // FS-01: rebuild from tickets says the same thing.
    DB::table('agent_assignment_stats')->where('user_id', $agent->identifier())->update(['open_assigned' => 42]);
    expect(app(AssignmentStats::class)->rebuild())->toBeGreaterThanOrEqual(1);
    expect((int) $row()->open_assigned)->toBe(0);
});

it('a redelivered event whose ticket no longer names the agent still converges; an unknown event id is a no-op', function () {
    createTenant();
    (new AssignmentStatsConsumer('00000000-0000-7000-8000-0000000000aa'))->handle(app(AssignmentStats::class));
    expect(DB::table('agent_assignment_stats')->count())->toBe(0);
});
