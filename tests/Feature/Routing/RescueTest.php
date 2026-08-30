<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\SystemViews;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Rescue\HeartbeatRescue;
use App\Domain\Routing\Rescue\OrphanRescue;
use App\Domain\Routing\Rescue\RescueFlags;
use App\Domain\Routing\Rescue\RescueSweep;
use App\Domain\Routing\Rescue\RescueTimers;
use App\Domain\Routing\Rescue\SystemReturn;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Jobs\Routing\RescueConsumer;
use App\Support\Events\ConsumerRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-16 a/b/c / EC-06 / AC-06 — Rescue (S11 step 9; P-C1/P-C2/P-C3 landed). The
| AC-06 three fixtures: heartbeat-loss (timer → flag), unreachable (immediate, never
| auto-acted), orphan (auto-move + note). The auto-return leg waits on P-C6 (the
| SystemReturn door) — here the port's ABSENCE is proven loud, not silent.
|--------------------------------------------------------------------------
*/

/** @return array{0: string, 1: string, 2: string, 3: App\Models\User, 4: App\Models\User} [tenantId, paymentsDept, paymentsQueue, admin, agent] */
function rescueScene(): array
{
    $tenantId = createTenant()->identifier();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $admin = ticketActor(['org.queues.manage', 'tickets.view', 'tickets.assign', 'tickets.status.change'], 'Fixture Rescue Admin');
    $agent = ticketActor(['tickets.view', 'tickets.assign_self', 'tickets.status.change'], 'Fixture Rescue Agent');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());

    return [$tenantId, $payments, $queue, $admin, $agent];
}

function rescueTicketHeldBy(string $departmentId, App\Models\User $agent, App\Models\User $assigner): App\Models\Ticket
{
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $departmentId));
    app(App\Domain\Tickets\Placement\PlaceInQueue::class)->place($ticket, (string) DB::table('departments')->where('id', $departmentId)->value('default_queue_id'), null, 'fixture');
    app(AssignTicket::class)->assign($ticket->fresh(), $agent, $assigner);

    return $ticket->fresh();
}

it('FR-16a / AC-06 heartbeat fixture: heartbeat loss with open assigned tickets ARMS the rescue timer (routing.rescue.minutes); a return disarms it; the due visit FLAGS every held ticket (rescue.flagged{heartbeat_lost}, audited system) and the Rescue view shows them in BOTH forms (P-C2)', function () {
    [$tenantId, $payments, , $admin, $agent] = rescueScene();
    $t1 = rescueTicketHeldBy($payments, $agent, $admin);
    $t2 = rescueTicketHeldBy($payments, $agent, $admin);
    $timers = app(RescueTimers::class);
    $rescue = app(HeartbeatRescue::class);

    // The consumer path: a REAL agent.status_changed{heartbeat_lost} (materialized by AgentStatus::current on a dead heartbeat).
    app(AgentStatus::class)->set($agent, 'online');
    Redis::connection('volatile')->del(sprintf('t:%s:agent_heartbeat:%s', $tenantId, $agent->identifier()));
    expect(app(AgentStatus::class)->current($agent->identifier())['reason'])->toBe('heartbeat_lost');
    $eventId = relayOutboxRow('agent.status_changed');
    expect(in_array(RescueConsumer::class, ConsumerRegistry::consumersFor('agent.status_changed'), true))->toBeTrue('the rescue consumer is not registered (ADR 0011)');
    (new RescueConsumer($eventId))->handle($rescue, app(OrphanRescue::class), app(App\Domain\Routing\Reopen\CourtesyHold::class));
    $score = $timers->score($tenantId, HeartbeatRescue::KIND, $agent->identifier());
    expect($score)->not->toBeNull('the heartbeat-rescue timer was not armed')
        ->and($score - $timers->now())->toBeGreaterThan(9 * 60)->toBeLessThanOrEqual(10 * 60);
    // Not due yet: the sweep leaves it; nothing flagged.
    expect(app(RescueFlags::class)->live($t1->identifier()))->toBeNull();

    // They come back → disarmed.
    app(AgentStatus::class)->set($agent, 'online');
    (new RescueConsumer(relayOutboxRow('agent.status_changed')))->handle($rescue, app(OrphanRescue::class), app(App\Domain\Routing\Reopen\CourtesyHold::class));
    expect($timers->score($tenantId, HeartbeatRescue::KIND, $agent->identifier()))->toBeNull('a returning agent did not disarm the timer');

    // Lost again, and the deadline is DUE (the timer re-armed in the past): the visit flags both tickets.
    Redis::connection('volatile')->del(sprintf('t:%s:agent_heartbeat:%s', $tenantId, $agent->identifier()));
    app(AgentStatus::class)->current($agent->identifier());
    $timers->arm($tenantId, HeartbeatRescue::KIND, $agent->identifier(), 1);
    expect($timers->due())->toContain($timers->member($tenantId, HeartbeatRescue::KIND, $agent->identifier()));
    $counts = app(RescueSweep::class)->sweep();
    expect($counts['flagged'] ?? 0)->toBeGreaterThanOrEqual(1);
    foreach ([$t1, $t2] as $t) {
        expect(app(RescueFlags::class)->live($t->identifier()))->not->toBeNull('a held ticket was not flagged');
        expect(DB::table('tickets')->where('id', $t->identifier())->value('assignee_id'))->toBe($agent->identifier(), 'routing.rescue.auto is OFF — the ticket must stay with the agent');
    }
    $event = json_decode((string) DB::table('outbox')->where('name', 'rescue.flagged')->orderByDesc('id')->value('payload'), true);
    expect($event['reason'])->toBe('heartbeat_lost')->and($event['ticket_id'])->toBe($t2->identifier());
    expect(DB::table('audit_log')->where('action_key', 'routing.rescue.flagged')->count())->toBe(2)
        ->and($timers->score($tenantId, HeartbeatRescue::KIND, $agent->identifier()))->toBeNull('the timer outlived its flagging visit');
    // A second visit is idempotent: one live flag per ticket (the partial unique), no second event.
    $events = DB::table('outbox')->where('name', 'rescue.flagged')->count();
    $rescue->visit($agent->identifier());
    expect(DB::table('outbox')->where('name', 'rescue.flagged')->count())->toBe($events)
        ->and(DB::table('rescue_flags')->where('ticket_id', $t1->identifier())->count())->toBe(1);

    // P-C2 BOTH FORMS: the direct query and the projector agree — the flagged tickets are IN Rescue.
    $direct = SystemViews::directQuery('rescue')->pluck('id')->all();
    expect($direct)->toContain($t1->identifier())->and($direct)->toContain($t2->identifier());
    runListProjector(relayOutboxRow('rescue.flagged'));
    expect(app(ViewZsets::class)->allIds('rescue'))->toContain($t2->identifier());
    // Cleared → LEAVES Rescue in both forms (the projector recompute on rescue.auto_returned's shape is the same row read).
    app(RescueFlags::class)->clear($t2->identifier(), autoReturned: true);
    expect(SystemViews::directQuery('rescue')->pluck('id')->all())->not->toContain($t2->identifier());
    app(App\Domain\Inbox\ReadModels\ListProjector::class)->recomputeTicket($t2->identifier());
    expect(app(ViewZsets::class)->allIds('rescue'))->not->toContain($t2->identifier())
        ->and(DB::table('rescue_flags')->where('ticket_id', $t2->identifier())->value('auto_returned'))->toBeTrue();
});

it('FR-16a with routing.rescue.auto ON: the return needs the SystemReturn door (P-C6) — unbound, the visit FLAGS, refuses the return LOUDLY (metric + deferred outcome) and KEEPS the timer; nothing is silently skipped', function () {
    [$tenantId, $payments, , $admin, $agent] = rescueScene();
    $t = rescueTicketHeldBy($payments, $agent, $admin);
    app(App\Support\Settings\SettingsRepository::class)->set('routing.rescue.auto', true, tenantSurface: false);
    expect(app()->bound(SystemReturn::class))->toBeFalse('P-C6 landed? then this test moves to the 9b shape');
    Redis::connection('volatile')->del(sprintf('t:%s:agent_heartbeat:%s', $tenantId, $agent->identifier()));
    app(AgentStatus::class)->set($agent, 'online');
    Redis::connection('volatile')->del(sprintf('t:%s:agent_heartbeat:%s', $tenantId, $agent->identifier()));
    app(AgentStatus::class)->current($agent->identifier());
    app(RescueTimers::class)->arm($tenantId, HeartbeatRescue::KIND, $agent->identifier(), 1);

    $visit = app(HeartbeatRescue::class)->visit($agent->identifier());
    expect($visit['outcome'])->toBe('deferred')->and($visit['flagged'])->toBe(1)->and($visit['returned'])->toBe(0);
    expect(app(RescueFlags::class)->live($t->identifier()))->not->toBeNull()
        ->and(DB::table('tickets')->where('id', $t->identifier())->value('assignee_id'))->toBe($agent->identifier())
        ->and(app(RescueTimers::class)->score($tenantId, HeartbeatRescue::KIND, $agent->identifier()))->not->toBeNull('the deferred return dropped its timer');
});

it('FR-16b / AC-06 unreachable fixture: channel_unreachable populates Rescue IMMEDIATELY through Doc 05/07 (no timer, no flag) and routing NEVER auto-acts on it (Doc 06 BR-07): no flag row, no return, no record', function () {
    [$tenantId, $payments, , $admin, $agent] = rescueScene();
    $t = rescueTicketHeldBy($payments, $agent, $admin);
    DB::table('tickets')->where('id', $t->identifier())->update(['channel_unreachable' => true]);
    expect(SystemViews::directQuery('rescue')->pluck('id')->all())->toContain($t->identifier());
    app(App\Domain\Inbox\ReadModels\ListProjector::class)->recomputeTicket($t->identifier());
    expect(app(ViewZsets::class)->allIds('rescue'))->toContain($t->identifier());

    $decisions = DB::table('routing_decisions')->where('ticket_id', $t->identifier())->count();
    app(RescueSweep::class)->sweep();
    expect(DB::table('rescue_flags')->where('ticket_id', $t->identifier())->count())->toBe(0, 'routing flagged an unreachable ticket — Doc 05 owns that condition')
        ->and(DB::table('tickets')->where('id', $t->identifier())->value('assignee_id'))->toBe($agent->identifier())
        ->and(DB::table('routing_decisions')->where('ticket_id', $t->identifier())->count())->toBe($decisions);
    // The rescue package never names the column at all (static: nothing under Routing/Rescue reads channel_unreachable).
    foreach (glob(app_path('Domain/Routing/Rescue/*.php')) ?: [] as $file) {
        expect(str_contains((string) file_get_contents($file), 'channel_unreachable'))->toBeFalse(basename($file).' touches Doc 05\'s condition');
    }
});

it('FR-16c / EC-06 / AC-06 orphan fixture: org.queue.deleted → every unassigned open ticket of the queue is flagged orphaned, re-routed to the department default (trigger=orphan record, the PlaceInQueue door), the system note written, routing.orphan_moved audited, the flag cleared as auto_returned', function () {
    [$tenantId, $payments, , $admin] = rescueScene();
    $extra = app(QueueWriter::class)->create($payments, 'Payments overflow', $admin->identifier());
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $payments));
    app(App\Domain\Tickets\Placement\PlaceInQueue::class)->place($ticket, $extra, null, 'fixture');
    // "Deleted mid-flight": the queue is tombstoned and its event relayed (the wizard's delete is step 10's; the event contract is the catalog's).
    DB::table('queues')->where('id', $extra)->update(['deleted_at' => DB::raw('clock_timestamp()'), 'is_active' => false]);
    $eventId = inboxSeedEvent('org.queue.deleted', ['tenant_id' => $tenantId, 'queue_id' => $extra]);
    expect(in_array(RescueConsumer::class, ConsumerRegistry::consumersFor('org.queue.deleted'), true))->toBeTrue();
    (new RescueConsumer($eventId))->handle(app(HeartbeatRescue::class), app(OrphanRescue::class), app(App\Domain\Routing\Reopen\CourtesyHold::class));

    $default = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('queue_id'))->toBe($default, 'the orphan was not moved to the department default');
    $record = DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->where('trigger', 'orphan')->first();
    expect($record)->not->toBeNull()->and($record->queue_id)->toBe($default);
    $flag = DB::table('rescue_flags')->where('ticket_id', $ticket->identifier())->first();
    expect($flag->reason)->toBe('orphaned')->and($flag->cleared_at)->not->toBeNull()->and($flag->auto_returned)->toBeTrue();
    $audit = json_decode((string) DB::table('audit_log')->where('action_key', 'routing.orphan_moved')->orderByDesc('id')->value('payload'), true);
    expect($audit['deleted_queue_id'])->toBe($extra)->and($audit['default_queue_id'])->toBe($default);
    $marker = DB::table('messages')->where('conversation_id', $conversation->identifier())->where('kind', 'system')->orderByDesc('id')->value('channel_meta');
    expect(json_decode((string) $marker, true)['system_code'])->toBe('routing_orphan_moved');
    // Idempotent on redelivery: nothing left in the deleted queue → no second move, no second flag.
    (new RescueConsumer($eventId))->handle(app(HeartbeatRescue::class), app(OrphanRescue::class), app(App\Domain\Routing\Reopen\CourtesyHold::class));
    expect(DB::table('rescue_flags')->where('ticket_id', $ticket->identifier())->count())->toBe(1)
        ->and(DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->where('trigger', 'orphan')->count())->toBe(1);
});

it('P-C1 physics: one LIVE flag per ticket (the second refused by the partial unique), no DELETE for app, flagged_at app-unwritable', function () {
    [$tenantId, $payments, , $admin, $agent] = rescueScene();
    $t = rescueTicketHeldBy($payments, $agent, $admin);
    app(RescueFlags::class)->flag($t->identifier(), 'heartbeat_lost');
    try {
        DB::transaction(static fn () => DB::table('rescue_flags')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(), 'reason' => 'orphaned']));
        $this->fail('a second LIVE flag was accepted');
    } catch (Illuminate\Database\QueryException $e) {
        expect($e->getMessage())->toContain('rescue_flags_live_unique');
    }
    try {
        DB::transaction(static fn () => DB::statement('DELETE FROM rescue_flags'));
        $this->fail('app deleted from rescue_flags');
    } catch (Illuminate\Database\QueryException $e) {
        expect($e->getMessage())->toContain('permission denied');
    }
    try {
        DB::transaction(static fn () => DB::table('rescue_flags')->where('ticket_id', $t->identifier())->update(['flagged_at' => DB::raw('clock_timestamp()')]));
        $this->fail('app wrote flagged_at');
    } catch (Illuminate\Database\QueryException $e) {
        expect($e->getMessage())->toContain('permission denied');
    }
});
