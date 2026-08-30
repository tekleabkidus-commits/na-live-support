<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Dispatch\QueueDispatcher;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Routing\Waiting\WaitingSets;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Jobs\Routing\AvailabilityConsumer;
use App\Support\Events\ConsumerRegistry;
use Illuminate\Support\Facades\DB;

/*
| Doc 11 T-03 / FR-08 step 5 / FS-02 / EC-01 / EC-03 / NFR-02 — the per-queue dispatcher as pure
| event reaction: pop = READ-THEN-COMMIT (removal only after a successful assignment); a stale
| member is dropped; the availability consumer is the ONLY trigger (no polling).
*/
function dispatchScene(): array
{
    createTenant();
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    $admin = ticketActor(['org.queues.manage'], 'Fixture Dispatch Admin');
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');

    return [$admin, $payments, $queue];
}

function waitingTicket(string $payments): App\Models\Ticket
{
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $payments));
    app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $payments]);   // nobody online → waiting, in the ZSET

    return $ticket->fresh();
}

it('FS-02: the head is READ, not removed — a dying worker leaves the member; removal happens ONLY after the assignment committed', function () {
    [$admin, $payments, $queue] = dispatchScene();
    $t1 = waitingTicket($payments);
    $sets = app(WaitingSets::class);
    expect($sets->head($queue))->toBe([$t1->identifier()])->and($sets->depth($queue))->toBe(1);
    // "Pop" with nobody online: the dispatcher reads the head and stops — the member is still there.
    expect(app(QueueDispatcher::class)->run($queue))->toBe(['assigned' => 0, 'waiting' => 1, 'skipped' => 0]);
    expect($sets->head($queue))->toBe([$t1->identifier()], 'the head was removed before any assignment — FS-02 violated');

    // An agent comes online → the dispatcher assigns and ONLY THEN removes.
    $agent = ticketActor(['tickets.view'], 'Fixture Dispatch Agent');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
    app(AgentStatus::class)->set($agent, 'online');
    expect(app(QueueDispatcher::class)->run($queue))->toBe(['assigned' => 1, 'waiting' => 0, 'skipped' => 0]);
    expect(DB::table('tickets')->where('id', $t1->identifier())->value('assignee_id'))->toBe($agent->identifier())
        ->and($sets->depth($queue))->toBe(0)
        ->and(DB::table('routing_decisions')->where('ticket_id', $t1->identifier())->where('trigger', 'availability')->count())->toBe(1);
});

it('EC-01/EC-03: two waiting tickets, one free slot — exactly one is assigned at commit and the other keeps waiting; a stale member (assigned elsewhere) is dropped from the mirror', function () {
    [$admin, $payments, $queue] = dispatchScene();
    $t1 = waitingTicket($payments);
    $t2 = waitingTicket($payments);
    $agent = ticketActor(['tickets.view'], 'Fixture One-Slot Agent');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
    app(QueueWriter::class)->update($queue, ['capacity_per_agent' => 1], $admin->identifier());
    app(AgentStatus::class)->set($agent, 'online');

    $run = app(QueueDispatcher::class)->run($queue);
    expect($run['assigned'])->toBe(1)->and($run['waiting'])->toBe(1);
    $assigned = DB::table('tickets')->whereIn('id', [$t1->identifier(), $t2->identifier()])->whereNotNull('assignee_id')->count();
    expect($assigned)->toBe(1, 'over-capacity: both were assigned to a one-slot agent');

    // Stale member: the waiting ticket gets assigned by a human elsewhere; the next run drops it.
    $remaining = app(WaitingSets::class)->head($queue)[0];
    DB::table('tickets')->where('id', $remaining)->update(['assignee_id' => $agent->identifier()]);
    app(QueueWriter::class)->update($queue, ['capacity_per_agent' => 5], $admin->identifier());
    app(App\Domain\Routing\Capacity\AssignmentStats::class)->recompute($agent->identifier());
    $run = app(QueueDispatcher::class)->run($queue);
    expect($run['skipped'])->toBe(1)->and(app(WaitingSets::class)->depth($queue))->toBe(0);
});

it('the AvailabilityConsumer is the dispatcher\'s ONLY trigger (durable on five events) — an agent.status_changed=online event dispatches the agent\'s queues', function () {
    foreach (['agent.status_changed', 'ticket.status_changed', 'ticket.assigned', 'queue.membership_changed', 'queue.temporary_grant.created'] as $event) {
        expect(in_array(AvailabilityConsumer::class, ConsumerRegistry::consumersFor($event), true))->toBeTrue("{$event} lacks the availability consumer");
    }
    [$admin, $payments, $queue] = dispatchScene();
    $t1 = waitingTicket($payments);
    $agent = ticketActor(['tickets.view'], 'Fixture Online Agent');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
    app(AgentStatus::class)->set($agent, 'online');
    (new AvailabilityConsumer(relayOutboxRow('agent.status_changed')))->handle(app(QueueDispatcher::class));
    expect(DB::table('tickets')->where('id', $t1->identifier())->value('assignee_id'))->toBe($agent->identifier());
});
