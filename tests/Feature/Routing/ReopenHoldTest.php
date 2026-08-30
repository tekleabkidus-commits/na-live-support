<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Routing\Reopen\CourtesyHold;
use App\Domain\Routing\Rescue\HeartbeatRescue;
use App\Domain\Routing\Rescue\OrphanRescue;
use App\Domain\Routing\Rescue\RescueTimers;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Jobs\Routing\RescueConsumer;
use App\Support\Events\ConsumerRegistry;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 11 EC-08 (A-54) — the reopen courtesy hold: the record shows it, the timer arms on
| the RescueTimers ZSET (a reuse, not a new sweep species), release when they return;
| the re-route at expiry waits on P-C6 (loud, never silent).
|--------------------------------------------------------------------------
*/

function holdScene(): array
{
    $tenantId = createTenant()->identifier();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $agent = ticketActor(['tickets.view', 'tickets.assign', 'tickets.assign_self', 'tickets.status.change', 'tickets.reopen'], 'Fixture Hold Agent');
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $payments));
    app(AssignTicket::class)->claim($ticket, $agent);
    app(TicketTransitionService::class)->transition($ticket->fresh(), TicketStatus::Resolved, TransitionContext::staff($agent));

    return [$tenantId, $agent, $ticket->fresh()];
}

it('EC-08: a reopened ticket whose retained assignee is Away gets a HOLD — a routing_decisions row (trigger reopened, outcome waiting, hold_until/hold_for in the record) and the hold timer at wait_for_previous; an Online assignee gets no hold; the consumer is durable on ticket.reopened and idempotent', function () {
    [$tenantId, $agent, $ticket] = holdScene();
    app(AgentStatus::class)->set($agent, 'away');
    app(App\Domain\Tickets\Reopen\ReopenTicket::class)->reopenByStaff($ticket, $agent, 'customer wrote back — fixture');
    expect($ticket->fresh()->assignee_id)->toBe($agent->identifier(), 'precondition: Doc 06 retained the active assignee');
    $eventId = relayOutboxRow('ticket.reopened');
    expect(in_array(RescueConsumer::class, ConsumerRegistry::consumersFor('ticket.reopened'), true))->toBeTrue();
    (new RescueConsumer($eventId))->handle(app(HeartbeatRescue::class), app(OrphanRescue::class), app(CourtesyHold::class));

    $row = DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->where('trigger', 'reopened')->where('outcome', 'waiting')->first();
    expect($row)->not->toBeNull('no hold record');
    $record = json_decode((string) $row->record, true);
    $snapshot = json_decode((string) $row->snapshot, true);
    expect($record['steps'][0]['step'])->toBe('courtesy_hold')->and($record['steps'][0]['input']['hold_for'])->toBe($agent->identifier())
        ->and($snapshot['hold_for'])->toBe($agent->identifier());
    $timers = app(RescueTimers::class);
    $score = $timers->score($tenantId, CourtesyHold::KIND, $row->id);
    expect($score)->not->toBeNull()->and($score - $timers->now())->toBeGreaterThan(14 * 60)->toBeLessThanOrEqual(15 * 60)
        ->and($score)->toBe((int) $snapshot['hold_until']);
    // Redelivery: one hold, not two.
    (new RescueConsumer($eventId))->handle(app(HeartbeatRescue::class), app(OrphanRescue::class), app(CourtesyHold::class));
    expect(DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->where('trigger', 'reopened')->count())->toBe(1);

    // They come back before expiry: the visit RELEASES (the ticket stays theirs), timer gone.
    app(AgentStatus::class)->set($agent, 'online');
    expect(app(CourtesyHold::class)->visit($row->id))->toBe('released')
        ->and($timers->score($tenantId, CourtesyHold::KIND, $row->id))->toBeNull()
        ->and($ticket->fresh()->assignee_id)->toBe($agent->identifier());

    // An Online assignee at reopen → no hold at all.
    [, $agent2, $ticket2] = holdScene();
    app(AgentStatus::class)->set($agent2, 'online');
    expect(app(CourtesyHold::class)->onReopened($ticket2))->toBeNull();
});

it('EC-08 expiry — THE REAL PATH (9b, P-C6 landed; CONVERTED from step 9\'s loud-absence proof): still Away at the deadline → returned through Doc 06\'s door with the hold record as the linkage, the timer gone, and the unassigned trigger re-routes; the record shows the courtesy hold', function () {
    [$tenantId, $agent, $ticket] = holdScene();
    app(AgentStatus::class)->set($agent, 'away');
    app(App\Domain\Tickets\Reopen\ReopenTicket::class)->reopenByStaff($ticket, $agent, 'customer wrote back — fixture');
    $id = app(CourtesyHold::class)->onReopened($ticket->fresh());
    expect($id)->toBeString();
    app(RescueTimers::class)->arm($tenantId, CourtesyHold::KIND, (string) $id, 1);   // due now

    expect(app(CourtesyHold::class)->visit((string) $id))->toBe('returned')
        ->and(app(RescueTimers::class)->score($tenantId, CourtesyHold::KIND, (string) $id))->toBeNull()
        ->and($ticket->fresh()->assignee_id)->toBeNull('the hold expired but the ticket stayed with the Away agent');
    $audit = json_decode((string) DB::table('audit_log')->where('action_key', 'ticket.assigned')->where('entity_id', $ticket->identifier())->orderByDesc('id')->value('payload'), true);
    expect($audit['reason'])->toBe('reopen:hold_expired')->and($audit['routing_decision_id'])->toBe($id)->and($audit['new_assignee_id'])->toBeNull();
    // No rescue record for a hold: the hold record IS the explanation; no rescue.auto_returned either (it is not a rescue).
    expect(DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->where('trigger', 'rescue')->count())->toBe(0)
        ->and(DB::table('outbox')->where('name', 'rescue.auto_returned')->count())->toBe(0);
    $assignedEvent = relayOutboxRow('ticket.assigned');
    (new App\Jobs\Routing\TicketUnassignedRouter($assignedEvent))->handle(app(App\Domain\Routing\Pipeline\RoutingPipeline::class));
    expect(DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->where('trigger', 'unassigned')->count())->toBe(1);
});
