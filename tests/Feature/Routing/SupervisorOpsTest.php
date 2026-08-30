<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Dispatch\QueueDispatcher;
use App\Domain\Routing\Manual\Broadcast;
use App\Domain\Routing\Manual\Reassign;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Routing\RoutingRefused;
use App\Domain\Routing\Waiting\WaitingSets;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-15 (supervisor ops) / BR-03 / SEC-01 / SEC-03 / SEC-06 / FR-17 — S11 step 7
|--------------------------------------------------------------------------
|
| Reassign bypasses strategy and is attributed (manual_by; the record's trigger=manual);
| the router never overrides it (BR-03 — structural: automatic assignment is
| claimOnly). SEC-01 re-checks BOTH scopes. Force status is Doc 07's door, consumed.
| Broadcast is contract-only ([D16], P-C5 ruled as filed). Pull-in's HTTP door lands
| here; its physics are TemporaryMembershipTest's.
*/

function supActor(array $permissions, string $ticketScope, string $roleName): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        ['color' => 'role-agent', 'permissions' => $permissions, 'scopes' => ['tickets' => $ticketScope, 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All']],
    );
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

/** @return array{0: App\Models\Tenant, 1: string, 2: string, 3: string} [tenant, tenantId, paymentsDeptId, paymentsQueueId] */
function supScene(): array
{
    $tenant = createTenant();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    activateTenant($tenant);

    return [$tenant, $tenant->identifier(), $payments, $queue];
}

/** The request lifecycle unbinds the tenant at its end — rebind before any DB assertion that follows a door call. */
function supRebind(App\Models\Tenant $tenant): void
{
    bindTenant($tenant);
}

function supWaitingTicket(string $departmentId): Ticket
{
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $departmentId));
    app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $departmentId]);   // nobody online → waiting

    return $ticket->fresh();
}

// The Manager/Team Leader template shape (Doc 03 Appendix B): supervision.* rides with the ticket doors it
// drives — tickets.assign for the assign door, tickets.status.change for FR-05's new → open inside it.
const SUPERVISOR_PERMS = ['tickets.view', 'conversations.view', 'tickets.assign', 'tickets.status.change', 'supervision.reassign', 'supervision.broadcast', 'org.queues.pull_in'];

it('FR-15 / BR-03 / FR-17: a supervisor reassigns a WAITING ticket through the door — strategy bypassed, the record says trigger=manual with manual_by, routing.manual_reassign audited, the waiting mirror cleared; the dispatcher never "fixes" it afterwards', function () {
    [$tenant, , $payments, $queue] = supScene();
    $supervisor = supActor(SUPERVISOR_PERMS, 'All', 'S7 Supervisor All');
    $target = supActor(['tickets.view'], 'All', 'S7 Reassign Target');   // NOT a queue member, not online: manual wins anyway
    $ticket = supWaitingTicket($payments);
    expect(app(WaitingSets::class)->head($queue))->toBe([$ticket->identifier()]);

    $response = $this->actingAs($supervisor, 'staff')->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/reassign', ['assignee_id' => $target->identifier()]);
    $response->assertStatus(200);
    supRebind($tenant);
    expect($response->json('new_assignee_id'))->toBe($target->identifier())->and($response->json('old_assignee_id'))->toBeNull()->and($response->json('from_queue_is_rg'))->toBeFalse();

    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBe($target->identifier())
        ->and(DB::table('tickets')->where('id', $ticket->identifier())->value('status'))->toBe('open');   // FR-05 through the door
    $record = DB::table('routing_decisions')->where('id', $response->json('record_id'))->first();
    expect($record->trigger)->toBe('manual')->and($record->outcome)->toBe('manual')->and($record->assigned_to)->toBe($target->identifier())->and($record->strategy)->toBeNull();
    $steps = json_decode((string) $record->record, true)['steps'];
    expect($steps[0]['step'])->toBe('manual_reassign')->and($steps[0]['input']['manual_by'])->toBe($supervisor->identifier());
    $audit = DB::table('audit_log')->where('action_key', 'routing.manual_reassign')->orderByDesc('id')->first(['actor_id', 'payload']);
    $payload = json_decode((string) $audit->payload, true);
    expect($audit->actor_id)->toBe($supervisor->identifier())->and($payload['new_assignee_id'])->toBe($target->identifier())->and($payload['from_queue_is_rg'])->toBeFalse();
    expect(app(WaitingSets::class)->depth($queue))->toBe(0, 'the manual placement left the waiting mirror dirty');

    // BR-03: an eligible online member appears and the dispatcher runs — the supervisor's placement stands.
    $admin = supActor(['org.queues.manage'], 'All', 'S7 Sup Admin');
    $member = supActor(['tickets.view'], 'All', 'S7 Sup Member');
    app(QueueWriter::class)->addMember($queue, $member->identifier(), $admin->identifier());
    app(AgentStatus::class)->set($member, 'online');
    app(QueueDispatcher::class)->run($queue);
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBe($target->identifier(), 'BR-03: the router overrode a manual placement');
});

it('SEC-01 scope matrix: an out-of-scope ticket is the 404 pattern for the supervisor; a target who could not see the ticket once assigned is refused (Doc 06 FR-15 on the assignee); the permission is the door\'s (403 without supervision.reassign)', function () {
    [$tenant, , $payments, $queue] = supScene();
    $ticket = supWaitingTicket($payments);

    $deptSupervisor = supActor(SUPERVISOR_PERMS, 'Dept', 'S7 Supervisor Dept No Access');   // Dept scope, NO Payments access
    $supervisor = supActor(SUPERVISOR_PERMS, 'All', 'S7 Supervisor All 2');
    $deptTarget = supActor(['tickets.view'], 'Dept', 'S7 Dept Target No Access');
    $agentOnly = supActor(['tickets.view', 'tickets.assign'], 'All', 'S7 No Supervision');
    expect(fn () => app(Reassign::class)->reassign($ticket, $deptSupervisor, $deptSupervisor))->toThrow(RoutingRefused::class, 'ticket_out_of_scope');

    $this->actingAs($deptSupervisor, 'staff')->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/reassign', ['assignee_id' => $deptSupervisor->identifier()])
        ->assertStatus(404);
    supRebind($tenant);

    $refused = $this->actingAs($supervisor, 'staff')->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/reassign', ['assignee_id' => $deptTarget->identifier()]);
    $refused->assertStatus(422);
    supRebind($tenant);
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBeNull();

    $this->actingAs($agentOnly, 'staff')->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/reassign', ['assignee_id' => $supervisor->identifier()])
        ->assertStatus(403);
});

it('SEC-06 / BR-02\'s one exception: a ticket in the RG queue is reassigned by RG-permitted staff only (customers.rg_flag.view) and the audit carries from_queue_is_rg=true — the high-visibility class', function () {
    [$tenant] = supScene();
    $rgQueue = (string) DB::table('queues')->where('is_rg', true)->whereNull('deleted_at')->value('id');
    $rgDept = (string) DB::table('queues')->where('id', $rgQueue)->value('department_id');
    $ticket = supWaitingTicket($rgDept);
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('queue_id'))->toBe($rgQueue, 'precondition: the ticket sits in the RG queue');

    $plain = supActor(SUPERVISOR_PERMS, 'All', 'S7 Plain Supervisor');
    $rgStaff = supActor([...SUPERVISOR_PERMS, 'customers.rg_flag.view'], 'All', 'S7 RG Supervisor');
    $target = supActor(['tickets.view'], 'All', 'S7 RG Target');

    $refused = $this->actingAs($plain, 'staff')->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/reassign', ['assignee_id' => $target->identifier()]);
    $refused->assertStatus(422);
    supRebind($tenant);
    expect($refused->json('refusal_code'))->toBe('rg_staff_required')->and($refused->json('code'))->toBe('routing_refused');

    $ok = $this->actingAs($rgStaff, 'staff')->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/reassign', ['assignee_id' => $target->identifier()]);
    $ok->assertStatus(200);
    supRebind($tenant);
    $payload = json_decode((string) DB::table('audit_log')->where('action_key', 'routing.manual_reassign')->orderByDesc('id')->value('payload'), true);
    expect($payload['from_queue_is_rg'])->toBeTrue()->and($payload['ticket_id'])->toBe($ticket->identifier());
});

it('FR-15 pull-in DOOR: 201 with the grant linkage; the SEC-04 reason is validated at the door; the end door ends it (both audited by their declarations); an out-of-scope queue is the 404 pattern', function () {
    [$tenant, , , $queue] = supScene();
    $supervisor = supActor(SUPERVISOR_PERMS, 'All', 'S7 PullIn Door Supervisor');
    $agent = supActor(['tickets.view'], 'All', 'S7 PullIn Door Agent');

    $this->actingAs($supervisor, 'staff')->postJson('https://localhost/inbox/queues/'.$queue.'/pull-in', ['user_id' => $agent->identifier(), 'reason' => 'short'])->assertStatus(422);

    $created = $this->actingAs($supervisor, 'staff')->postJson('https://localhost/inbox/queues/'.$queue.'/pull-in', ['user_id' => $agent->identifier(), 'reason' => 'Evening cover for the Payments queue.']);
    $created->assertStatus(201);
    supRebind($tenant);
    expect($created->json('override_id'))->toBeString()->and($created->json('expires_at'))->toBeString()->and($created->json('hint'))->toBeNull();
    expect(DB::table('queue_members')->where('queue_id', $queue)->where('user_id', $agent->identifier())->where('kind', 'temporary')->whereNull('ended_at')->count())->toBe(1);

    $this->actingAs($supervisor, 'staff')->postJson('https://localhost/inbox/queues/'.$queue.'/pull-in/'.$agent->identifier().'/end')->assertStatus(200);
    supRebind($tenant);
    expect(DB::table('queue_members')->where('queue_id', $queue)->where('user_id', $agent->identifier())->whereNull('ended_at')->count())->toBe(0);

    $deptSupervisor = supActor(SUPERVISOR_PERMS, 'Dept', 'S7 PullIn Door Dept Supervisor');
    $this->actingAs($deptSupervisor, 'staff')->postJson('https://localhost/inbox/queues/'.$queue.'/pull-in', ['user_id' => $agent->identifier(), 'reason' => 'Out-of-scope pull-in attempt reason.'])->assertStatus(404);
    supRebind($tenant);
    $this->actingAs($supervisor, 'staff')->postJson('https://localhost/inbox/queues/'.(string) Illuminate\Support\Str::uuid7().'/pull-in', ['user_id' => $agent->identifier(), 'reason' => 'Unknown-queue pull-in attempt reason.'])->assertStatus(404);
});

it('FR-15 broadcast ([D16], P-C5): the door returns EXACTLY the delivery contract the fixture pins — recipients are the effective members (ids only), category the registered key; audited with recipient_count and never the text; NO bell rows written by Doc 11', function () {
    [$tenant, , , $queue] = supScene();
    $supervisor = supActor(SUPERVISOR_PERMS, 'All', 'S7 Broadcast Supervisor');
    $admin = supActor(['org.queues.manage'], 'All', 'S7 Broadcast Admin');
    $m1 = supActor(['tickets.view'], 'All', 'S7 Broadcast M1');
    $m2 = supActor(['tickets.view'], 'All', 'S7 Broadcast M2');
    app(QueueWriter::class)->addMember($queue, $m1->identifier(), $admin->identifier());
    app(QueueWriter::class)->addMember($queue, $m2->identifier(), $admin->identifier());
    $bellRows = DB::table('inbox_notifications')->count();

    $fixture = json_decode((string) file_get_contents(base_path('fixtures/routing/contracts/doc16-broadcast-delivery.json')), true, 512, JSON_THROW_ON_ERROR);
    $note = 'Deposits are delayed by the bank — tell customers 30 minutes.';
    $response = $this->actingAs($supervisor, 'staff')->postJson('https://localhost/inbox/queues/'.$queue.'/broadcast', ['note' => $note]);
    $response->assertStatus(200);
    supRebind($tenant);
    $delivery = $response->json('delivery');
    expect(array_keys($delivery))->toBe($fixture['keys'])
        ->and($delivery['queue_id'])->toBe($queue)
        ->and($delivery['category'])->toBe(Broadcast::CATEGORY)
        ->and($delivery['note'])->toBe($note);
    $expected = [$m1->identifier(), $m2->identifier()];
    sort($expected);
    expect($delivery['recipients'])->toBe($expected);

    $audit = DB::table('audit_log')->where('action_key', 'routing.broadcast_sent')->orderByDesc('id')->first(['payload']);
    $payload = json_decode((string) $audit->payload, true);
    expect($payload['recipient_count'])->toBe(2)->and($audit->payload)->not->toContain('Deposits are delayed');
    expect(DB::table('inbox_notifications')->count())->toBe($bellRows, 'Doc 11 wrote bell rows — delivery is Doc 16\'s ([D16])');

    supRebind($tenant);
    $deptSupervisor = supActor(SUPERVISOR_PERMS, 'Dept', 'S7 Broadcast Dept Supervisor');
    $this->actingAs($deptSupervisor, 'staff')->postJson('https://localhost/inbox/queues/'.$queue.'/broadcast', ['note' => $note])->assertStatus(404);
});

it('FR-15 force status is Doc 07\'s door, CONSUMED not re-implemented: inbox.status.force exists with supervision.agent_status.force + audit:agent.status_forced, and no second force route lives under Routing', function () {
    $route = Route::getRoutes()->getByName('inbox.status.force');
    expect($route)->not->toBeNull();
    $middleware = $route->gatherMiddleware();
    expect($middleware)->toContain('permission:supervision.agent_status.force')->and($middleware)->toContain('audit:agent.status_forced');
    expect($route->getActionName())->toContain('Inbox\\StatusNotificationsController');

    $routingForce = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->getActionName(), 'Controllers\\Routing\\') && str_contains($r->uri(), 'force'));
    expect($routingForce)->toHaveCount(0, 'a second force-status implementation exists under Routing');

    // The four supervisor doors carry their permission + audit declarations (the registry gates read these).
    foreach (['inbox.ticket.reassign' => ['supervision.reassign', 'routing.manual_reassign'], 'inbox.queue.pull_in' => ['org.queues.pull_in', 'routing.pull_in.created'], 'inbox.queue.pull_in.end' => ['org.queues.pull_in', 'routing.pull_in.ended'], 'inbox.queue.broadcast' => ['supervision.broadcast', 'routing.broadcast_sent']] as $name => [$perm, $audit]) {
        $mw = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];
        expect($mw)->toContain('permission:'.$perm)->and($mw)->toContain('audit:'.$audit);
    }
});
