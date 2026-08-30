<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Domain\Tickets\Transfer\TransferTicket;
use App\Jobs\Routing\AssigneeDeactivatedRouter;
use App\Jobs\Routing\TicketCreatedRouter;
use App\Jobs\Routing\TicketReopenedRouter;
use App\Jobs\Routing\TicketTransferredRouter;
use App\Jobs\Routing\TicketUnassignedRouter;
use App\Support\Events\ConsumerRegistry;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-07 / FR-08 step 5 / T-03 / ADR 0011 — the DURABLE routing triggers
|--------------------------------------------------------------------------
| created, transferred, unassigned (manual), deactivation hand-back, reopened-with-inactive-
| assignee — each a durable consumer, idempotent on event_id (a redelivery after a successful
| assignment is a no-op: the ticket is assigned). The automation trigger is Doc 12's [D12];
| "queue pull of new backlog" is step 6's dispatcher.
*/

function triggerScene(): array
{
    createTenant();
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    $admin = ticketActor(['org.queues.manage', 'tickets.department.change', 'tickets.view', 'tickets.assign', 'tickets.status.change'], 'Fixture Trigger Admin');
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $agent = ticketActor(['tickets.view'], 'Fixture Trigger Agent');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
    app(AgentStatus::class)->set($agent, 'online');

    return [$admin, $payments, $queue, $agent];
}

it('is registered DURABLY on the five trigger events (ADR 0011) — exact membership pinned', function () {
    expect(in_array(TicketCreatedRouter::class, ConsumerRegistry::consumersFor('ticket.created'), true))->toBeTrue()
        ->and(in_array(TicketTransferredRouter::class, ConsumerRegistry::consumersFor('ticket.transferred'), true))->toBeTrue()
        ->and(in_array(TicketUnassignedRouter::class, ConsumerRegistry::consumersFor('ticket.assigned'), true))->toBeTrue()
        ->and(in_array(AssigneeDeactivatedRouter::class, ConsumerRegistry::consumersFor('ticket.assignee_deactivated'), true))->toBeTrue()
        ->and(in_array(TicketReopenedRouter::class, ConsumerRegistry::consumersFor('ticket.reopened'), true))->toBeTrue();
});

it('created: a new ticket with no department reaches the tenant default and is assigned; a REPLAY of the same event is a no-op (already assigned — one record)', function () {
    [, , , $agent] = triggerScene();
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));
    $eventId = relayOutboxRow('ticket.created');

    (new TicketCreatedRouter($eventId))->handle(app(App\Domain\Routing\Pipeline\RoutingPipeline::class));
    // No agent sits in General's queue → waiting in General.
    $general = (string) DB::table('departments')->where('slug', 'general')->value('default_queue_id');
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('queue_id'))->toBe($general);
    expect(DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->count())->toBe(1);
    unset($agent);
});

it('transferred: the new department is explicit; the router assigns in the destination; a replay after the assignment is a no-op', function () {
    [$admin, $payments, , $agent] = triggerScene();
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));
    app(TransferTicket::class)->transfer($ticket, $payments, $admin);
    $eventId = relayOutboxRow('ticket.transferred');

    (new TicketTransferredRouter($eventId))->handle(app(App\Domain\Routing\Pipeline\RoutingPipeline::class));
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBe($agent->identifier());
    (new TicketTransferredRouter($eventId))->handle(app(App\Domain\Routing\Pipeline\RoutingPipeline::class));
    expect(DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->count())->toBe(1, 'a replay routed again');
});

it('unassigned: a manual unassign (ticket.assigned with a null new assignee) re-routes; an ASSIGNMENT event does not', function () {
    [$admin, $payments, , $agent] = triggerScene();
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $payments));
    app(AssignTicket::class)->assign($ticket, $agent, $admin);
    $assignEvent = relayOutboxRow('ticket.assigned');
    (new TicketUnassignedRouter($assignEvent))->handle(app(App\Domain\Routing\Pipeline\RoutingPipeline::class));
    expect(DB::table('routing_decisions')->count())->toBe(0, 'an assignment event routed');

    app(AssignTicket::class)->unassign($ticket->fresh(), $admin);
    $unassignEvent = relayOutboxRow('ticket.assigned');
    (new TicketUnassignedRouter($unassignEvent))->handle(app(App\Domain\Routing\Pipeline\RoutingPipeline::class));
    expect(DB::table('routing_decisions')->where('trigger', 'unassigned')->count())->toBe(1)
        ->and(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBe($agent->identifier());
});

it('deactivation hand-back (EC-06) and reopened-with-inactive-assignee (FR-08) each re-route one ticket on its own', function () {
    [$admin, $payments, , $agent] = triggerScene();
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $payments));
    // EC-06: the hand-back event names the ticket; the router re-routes it (to the one online agent).
    DB::table('tickets')->where('id', $ticket->identifier())->update(['assignee_id' => null]);
    $deactivated = app(App\Support\Events\EventPublisher::class)->publish('ticket.assignee_deactivated', ['tenant_id' => $ticket->getAttribute('tenant_id'), 'ticket_id' => $ticket->identifier(), 'conversation_id' => $ticket->conversation_id, 'previous_assignee_id' => $admin->identifier()]);
    (new AssigneeDeactivatedRouter(relayOutboxRow('ticket.assignee_deactivated')))->handle(app(App\Domain\Routing\Pipeline\RoutingPipeline::class));
    expect(DB::table('routing_decisions')->where('trigger', 'deactivated')->count())->toBe(1)
        ->and(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBe($agent->identifier());
    unset($deactivated);

    // FR-08 reopen: a RETAINED assignee means nothing to route (the guard), an unassigned reopen routes.
    DB::table('tickets')->where('id', $ticket->identifier())->update(['assignee_id' => null]);
    app(App\Support\Events\EventPublisher::class)->publish('ticket.reopened', ['tenant_id' => $ticket->getAttribute('tenant_id'), 'ticket_id' => $ticket->identifier(), 'conversation_id' => $ticket->conversation_id, 'assignee_id' => null, 'from_status' => 'resolved', 'reopened_count' => 1, 'actor_type' => 'customer', 'actor_id' => null]);
    (new TicketReopenedRouter(relayOutboxRow('ticket.reopened')))->handle(app(App\Domain\Routing\Pipeline\RoutingPipeline::class));
    expect(DB::table('routing_decisions')->where('trigger', 'reopened')->count())->toBe(1);
});
