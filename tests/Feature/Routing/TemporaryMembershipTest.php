<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Membership\EffectiveMembers;
use App\Domain\Org\Membership\OrgMembership;
use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Dispatch\QueueDispatcher;
use App\Domain\Routing\Eligibility\RoutableAgents;
use App\Domain\Routing\Hours\HoursContract;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Routing\RoutingRefused;
use App\Domain\Routing\Temporary\ExpirySweep;
use App\Domain\Routing\Temporary\ExpiryZset;
use App\Domain\Routing\Temporary\PullIn;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-15 (pull-in) / FR-03's temporary half / T-07 / BR-07 / SEC-01 / SEC-03 /
| EC-07 / FS-05 / AC-05 — temporary membership; Doc 03 FR-09's second half; A-50
| obligation 4; A-53; A-56 (S11 step 7)
|--------------------------------------------------------------------------
|
| AC-05: "creation via pull-in → Doc 03 grant linkage, expiry via sweep AND
| assignment-time check independently (disable one path in test, security holds),
| recurrence hint at 3× (BR-07)". Each expiry path is proven ALONE: with the sweep
| never run the resolver refuses (T-07's "resolver time check"); with the resolver
| untouched the sweep ends the rows (the cleanup-lag half). Sabotage #8 (a/b/c) lands
| on the named assertions below.
*/

function s7Actor(array $permissions, string $ticketScope, string $roleName): User
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

/** @return array{0: string, 1: string, 2: string, 3: User} [tenantId, departmentId(payments), queueId, granter] */
function pullInScene(): array
{
    $tenantId = createTenant()->identifier();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    // The granter: All-scoped supervisor holding org.queues.pull_in (SEC-01's reach is satisfied by All).
    $granter = s7Actor(['tickets.view', 'org.queues.pull_in', 'org.queues.manage'], 'All', 'S7 PullIn Granter');

    return [$tenantId, $payments, $queue, $granter];
}

/** An expired temporary trio (override + queue row + department row) planted directly — the sweep's input. */
function expiredPullInRows(string $tenantId, string $queue, string $department, User $agent, User $granter, string $expiresSql = "clock_timestamp() - interval '1 minute'"): string
{
    $overrideId = (string) Str::uuid7();
    DB::table('user_permission_overrides')->insert([
        'id' => $overrideId, 'tenant_id' => $tenantId, 'user_id' => $agent->identifier(), 'permission' => PullIn::GRANTED_PERMISSION, 'effect' => 'allow',
        'reason' => 'expired pull-in fixture row', 'granted_by' => $granter->identifier(), 'expires_at' => DB::raw($expiresSql),
    ]);
    DB::table('queue_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'queue_id' => $queue, 'user_id' => $agent->identifier(), 'kind' => 'temporary', 'granted_by' => $granter->identifier(), 'override_id' => $overrideId, 'expires_at' => DB::raw($expiresSql)]);
    DB::table('user_departments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $agent->identifier(), 'department_id' => $department, 'kind' => 'temporary', 'granted_by' => $granter->identifier(), 'override_id' => $overrideId, 'expires_at' => DB::raw($expiresSql)]);

    return $overrideId;
}

it('AC-05 / FR-15 / SEC-03 / A-56: a pull-in creates the Doc 03 grant THROUGH OverrideAdministration (tickets.assign_self, audited rbac.override.added) and the queue + department temporary rows on that ONE override_id, in one transaction; the agent becomes effective/routable and can claim; the timer member is armed', function () {
    [$tenantId, $payments, $queue, $granter] = pullInScene();
    $agent = s7Actor(['tickets.view'], 'Dept', 'S7 PullIn Agent Dept');   // Dept-scoped with NO department access: the FR-04 config-error shape a pull-in must resolve

    expect(app(EffectiveMembers::class)->eligible($queue))->not->toContain($agent->identifier());

    $result = app(PullIn::class)->create($granter, $queue, $agent->identifier(), null, 'Match-night cover for the Payments queue.');

    $override = DB::table('user_permission_overrides')->where('id', $result['override_id'])->first();
    expect($override)->not->toBeNull()
        ->and($override->permission)->toBe('tickets.assign_self')
        ->and($override->effect)->toBe('allow')
        ->and($override->revoked_at)->toBeNull()
        ->and($override->granted_by)->toBe($granter->identifier());
    // SEC-03: the grant was Doc 03's act — its own audit entry exists for this subject.
    expect(DB::table('audit_log')->where('action_key', 'rbac.override.added')->where('entity_id', $agent->identifier())->count())->toBe(1);

    $qm = DB::table('queue_members')->where('queue_id', $queue)->where('user_id', $agent->identifier())->whereNull('ended_at')->first();
    $ud = DB::table('user_departments')->where('department_id', $payments)->where('user_id', $agent->identifier())->whereNull('ended_at')->first();
    expect($qm->kind)->toBe('temporary')->and($qm->override_id)->toBe($result['override_id'])
        ->and($ud->kind)->toBe('temporary')->and($ud->override_id)->toBe($result['override_id'], 'the three pull-in rows do not share ONE override_id (A-50 obligation 4)')
        ->and($qm->expires_at)->toBe($ud->expires_at);

    // FR-04: effective now (the department row resolved the config error); FR-05: routable once online.
    expect(app(EffectiveMembers::class)->eligible($queue))->toContain($agent->identifier());
    app(AgentStatus::class)->set($agent, 'online');
    expect(array_column(app(RoutableAgents::class)->forQueue($queue), 'user_id'))->toContain($agent->identifier());
    // A-56: the grant's purpose — the pulled-in agent can CLAIM from the queue (tickets.assign_self).
    expect(app(CapabilityMapProvider::class)->verdictFor($agent, 'tickets.assign_self')->allowed)->toBeTrue();

    $audit = json_decode((string) DB::table('audit_log')->where('action_key', 'routing.pull_in.created')->orderByDesc('id')->value('payload'), true);
    expect($audit['queue_id'])->toBe($queue)->and($audit['user_id'])->toBe($agent->identifier())->and($audit['override_id'])->toBe($result['override_id']);
    $event = json_decode((string) DB::table('outbox')->where('name', 'queue.temporary_grant.created')->orderByDesc('id')->value('payload'), true);
    expect($event['override_id'])->toBe($result['override_id'])->and($event['expires_at'])->toBe($result['expires_at']);
    expect(app(ExpiryZset::class)->has($tenantId, $result['override_id']))->toBeTrue('T-07: the expiry timer member was not armed');
    expect($result['hint'])->toBeNull();
});

it('A-50 obligation 3 shape for the pull-in: a failure on the LAST row rolls back the grant and the queue row — a partial pull-in cannot exist', function () {
    [, , $queue, $granter] = pullInScene();
    $agent = s7Actor(['tickets.view'], 'Dept', 'S7 PullIn Agent Dept Partial');

    $listener = static function (Illuminate\Database\Events\QueryExecuted $q): void {
        if (str_contains($q->sql, 'insert into "user_departments"')) {
            throw new RuntimeException('planted failure after the department row');
        }
    };
    DB::listen($listener);
    try {
        expect(fn () => app(PullIn::class)->create($granter, $queue, $agent->identifier(), null, 'Partial-write fixture reason.'))->toThrow(RuntimeException::class, 'planted failure');
    } finally {
        // DB::listen has no unlisten — the dispatcher's forget on the event class drops the closure.
        app('events')->forget(Illuminate\Database\Events\QueryExecuted::class);
    }
    expect(DB::table('queue_members')->where('user_id', $agent->identifier())->count())->toBe(0, 'the queue row survived the failed pull-in')
        ->and(DB::table('user_permission_overrides')->where('user_id', $agent->identifier())->whereNull('revoked_at')->count())->toBe(0, 'the grant survived the failed pull-in');
});

it('A-53 / FR-15: the expiry DEFAULTS to the tenant-local shift end from the hours contract and is editable; a past expiry is refused (DB time)', function () {
    [, $payments, $queue, $granter] = pullInScene();
    $agent = s7Actor(['tickets.view'], 'All', 'S7 PullIn Agent Default');
    $expected = app(HoursContract::class)->shiftEnd($payments);

    $result = app(PullIn::class)->create($granter, $queue, $agent->identifier(), null, 'Default-expiry fixture reason.');
    expect(new DateTimeImmutable($result['expires_at']))->toEqual($expected)
        ->and(new DateTimeImmutable($result['expires_at']) > app(HoursContract::class)->now())->toBeTrue();
    app(PullIn::class)->end($granter, $queue, $agent->identifier());

    $custom = app(HoursContract::class)->now()->modify('+3 hours');
    $edited = app(PullIn::class)->create($granter, $queue, $agent->identifier(), $custom, 'Edited-expiry fixture reason.');
    expect((new DateTimeImmutable($edited['expires_at']))->format('U'))->toBe($custom->format('U'));
    app(PullIn::class)->end($granter, $queue, $agent->identifier());

    expect(fn () => app(PullIn::class)->create($granter, $queue, $agent->identifier(), app(HoursContract::class)->now()->modify('-1 minute'), 'Past-expiry fixture reason.'))
        ->toThrow(RoutingRefused::class, 'expiry_in_past');
});

it('SEC-01: pull-in requires org.queues.pull_in AND the queue within the granter\'s scope; an inactive queue and a second concurrent pull-in of the same agent are refused with stable codes', function () {
    [$tenantId, $payments, $queue, $granter] = pullInScene();
    $agent = s7Actor(['tickets.view'], 'All', 'S7 PullIn Agent Sec');
    $noPerm = s7Actor(['tickets.view', 'org.queues.manage'], 'All', 'S7 No PullIn Perm');
    expect(fn () => app(PullIn::class)->create($noPerm, $queue, $agent->identifier(), null, 'No-permission fixture reason.'))->toThrow(RoutingRefused::class, 'forbidden');

    $deptOnly = s7Actor(['tickets.view', 'org.queues.pull_in'], 'Dept', 'S7 Dept PullIn Granter');   // Dept scope with NO Payments access
    expect(fn () => app(PullIn::class)->create($deptOnly, $queue, $agent->identifier(), null, 'Out-of-scope fixture reason.'))->toThrow(RoutingRefused::class, 'queue_out_of_scope');

    $deposits = (string) DB::table('departments')->where('slug', 'deposits')->value('id');
    $depositsQueue = (string) DB::table('departments')->where('id', $deposits)->value('default_queue_id');
    app(PullIn::class)->create($granter, $queue, $agent->identifier(), null, 'First pull-in fixture reason.');
    expect(fn () => app(PullIn::class)->create($granter, $queue, $agent->identifier(), null, 'Duplicate pull-in fixture reason.'))->toThrow(RoutingRefused::class, 'already_member')
        ->and(fn () => app(PullIn::class)->create($granter, $depositsQueue, $agent->identifier(), null, 'Second concurrent pull-in reason.'))->toThrow(RoutingRefused::class, 'pull_in_active');

    $extra = app(QueueWriter::class)->create($payments, 'Payments overflow', $granter->identifier());
    app(QueueWriter::class)->deactivate($extra, $granter->identifier());
    $other = s7Actor(['tickets.view'], 'All', 'S7 PullIn Agent Inactive');
    expect(fn () => app(PullIn::class)->create($granter, $extra, $other->identifier(), null, 'Inactive-queue fixture reason.'))->toThrow(RoutingRefused::class, 'queue_inactive');
});

it('T-07 / FS-05 — PATH A ALONE (sweep never run): an expired temporary member is refused at assignment time by DB time — not effective, not routable, no department reach — while the rows are still un-ended (sabotage #8b red here)', function () {
    [$tenantId, $payments, $queue, $granter] = pullInScene();
    $agent = s7Actor(['tickets.view'], 'Dept', 'S7 Expired Agent');
    $overrideId = expiredPullInRows($tenantId, $queue, $payments, $agent, $granter);
    app(AgentStatus::class)->set($agent, 'online');

    expect(DB::table('queue_members')->where('override_id', $overrideId)->whereNull('ended_at')->count())->toBe(1, 'precondition: the sweep must NOT have run');
    // HARDENED (S11 step 7 honest trail): `toContain($needle, $message)` treats the message as a SECOND
    // needle, so `->not->toContain(id, 'msg')` is always green — an invalid green caught by the 8b probe.
    expect(in_array($agent->identifier(), app(EffectiveMembers::class)->eligible($queue), true))->toBeFalse('AC-05: an expired temporary member is still effective before the sweep')
        ->and(in_array($agent->identifier(), array_column(app(RoutableAgents::class)->forQueue($queue), 'user_id'), true))->toBeFalse('AC-05: an expired temporary member is still routable before the sweep')
        ->and(in_array($payments, app(OrgMembership::class)->departmentIdsFor($agent), true))->toBeFalse('AC-05: expired department access still resolves before the sweep');

    // And the dispatcher, with a ticket waiting, assigns nothing to them.
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $payments));
    app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $payments]);
    app(QueueDispatcher::class)->run($queue);
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBeNull('an expired pull-in received an assignment');
    expect(DB::table('queue_members')->where('override_id', $overrideId)->whereNull('ended_at')->count())->toBe(1, 'the resolver path must not depend on ending rows');
});

it('T-07 — PATH B ALONE (the sweep, resolver untouched): the due member ends BOTH rows jointly (ended_at, never deleted), publishes queue.temporary_grant.expired{cause=expired}, audits routing.pull_in.ended (system) and drops its timer member; a second pass is a no-op (sabotage #8c red here)', function () {
    [$tenantId, $payments, $queue, $granter] = pullInScene();
    $agent = s7Actor(['tickets.view'], 'All', 'S7 Sweep Agent');
    $overrideId = expiredPullInRows($tenantId, $queue, $payments, $agent, $granter);
    app(ExpiryZset::class)->add($tenantId, $overrideId, 1);   // due long ago

    $sweep = app(ExpirySweep::class);
    expect($sweep->sweepOne($tenantId, $overrideId))->toBe('ended');

    expect(DB::table('queue_members')->where('override_id', $overrideId)->whereNotNull('ended_at')->count())->toBe(1, 'CLEANUP LAG: the sweep did not end the queue row')
        ->and(DB::table('user_departments')->where('override_id', $overrideId)->whereNotNull('ended_at')->count())->toBe(1, 'CLEANUP LAG: the sweep did not end the department row')
        ->and(DB::table('queue_members')->where('override_id', $overrideId)->count())->toBe(1, 'FLAG C: the row was deleted');
    $event = json_decode((string) DB::table('outbox')->where('name', 'queue.temporary_grant.expired')->orderByDesc('id')->value('payload'), true);
    expect($event['override_id'])->toBe($overrideId)->and($event['cause'])->toBe('expired')->and($event['queue_id'])->toBe($queue);
    $audit = DB::table('audit_log')->where('action_key', 'routing.pull_in.ended')->orderByDesc('id')->first(['actor_type', 'payload']);
    expect($audit->actor_type)->toBe('system')->and(json_decode((string) $audit->payload, true)['cause'])->toBe('expired');
    expect(app(ExpiryZset::class)->has($tenantId, $overrideId))->toBeFalse('the timer member outlived the sweep');

    // Idempotent: nothing live → dropped, no second event.
    $events = DB::table('outbox')->where('name', 'queue.temporary_grant.expired')->count();
    expect($sweep->sweepOne($tenantId, $overrideId))->toBe('dropped')
        ->and(DB::table('outbox')->where('name', 'queue.temporary_grant.expired')->count())->toBe($events);

    // Not yet due by DB time → deferred, rows untouched, member kept.
    $future = s7Actor(['tickets.view'], 'All', 'S7 Future Agent');
    $futureOverride = expiredPullInRows($tenantId, $queue, $payments, $future, $granter, "clock_timestamp() + interval '1 hour'");
    app(ExpiryZset::class)->add($tenantId, $futureOverride, 1);
    expect($sweep->sweepOne($tenantId, $futureOverride))->toBe('deferred')
        ->and(DB::table('queue_members')->where('override_id', $futureOverride)->whereNull('ended_at')->count())->toBe(1)
        ->and(app(ExpiryZset::class)->has($tenantId, $futureOverride))->toBeTrue();

    // Re-armed at its REAL expiry (what routing:rebuild would do), the member is off the DB-time short-list.
    app(ExpiryZset::class)->add($tenantId, $futureOverride, (int) app(HoursContract::class)->now()->modify('+1 hour')->format('U'));
    expect(app(ExpiryZset::class)->due())->not->toContain(app(ExpiryZset::class)->member($tenantId, $futureOverride));
});

it('EC-07: expiry mid-conversation — the assignment already made PERSISTS through the sweep (the ticket stays with the agent) and no new assignment reaches them', function () {
    [$tenantId, $payments, $queue, $granter] = pullInScene();
    $agent = s7Actor(['tickets.view'], 'All', 'S7 EC07 Agent');
    // The pull-in has run out (rows planted expired — expires_at is app-unwritable, R-19b, so the
    // fixture plants the trio directly); the agent still HOLDS a ticket assigned during the shift.
    $overrideId = expiredPullInRows($tenantId, $queue, $payments, $agent, $granter);
    $assigner = s7Actor(['tickets.view', 'tickets.assign', 'tickets.status.change'], 'All', 'S7 EC07 Assigner');
    [, $conversation] = conversationFixture();
    $held = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $payments));
    app(AssignTicket::class)->assign($held, $agent, $assigner);
    expect(DB::table('tickets')->where('id', $held->identifier())->value('assignee_id'))->toBe($agent->identifier(), 'precondition: the agent holds a ticket');
    app(AgentStatus::class)->set($agent, 'online');

    expect(app(ExpirySweep::class)->sweepOne($tenantId, $overrideId))->toBe('ended');

    expect(DB::table('tickets')->where('id', $held->identifier())->value('assignee_id'))->toBe($agent->identifier(), 'EC-07: the expiry unassigned a held ticket');

    [, $conversation2] = conversationFixture();
    $next = app(CreateTicket::class)->create(new NewTicket($conversation2, TicketSource::Agent, departmentId: $payments));
    app(RoutingPipeline::class)->route($next, 'created', ['explicit_department_id' => $payments]);
    app(QueueDispatcher::class)->run($queue);
    expect(DB::table('tickets')->where('id', $next->identifier())->value('assignee_id'))->toBeNull('a new assignment reached an expired pull-in');
});

it('BR-07: the same pull-in recurring ≥ routing.pull_in.recurrence_hint (3) times in a week returns the "make permanent?" KEY — a nudge only, nothing converted', function () {
    [, , $queue, $granter] = pullInScene();
    $agent = s7Actor(['tickets.view'], 'All', 'S7 Recurring Agent');
    $hints = [];
    for ($i = 1; $i <= 3; $i++) {
        $hints[] = app(PullIn::class)->create($granter, $queue, $agent->identifier(), null, sprintf('Recurring pull-in #%d fixture reason.', $i))['hint'];
        app(PullIn::class)->end($granter, $queue, $agent->identifier());
    }
    expect($hints)->toBe([null, null, 'routing.notify.pull_in_recurring']);
    expect(DB::table('queue_members')->where('queue_id', $queue)->where('user_id', $agent->identifier())->where('kind', 'direct')->count())->toBe(0, 'BR-07: the hint converted a pull-in')
        ->and(DB::table('queue_members')->where('queue_id', $queue)->where('user_id', $agent->identifier())->where('kind', 'temporary')->count())->toBe(3);
    expect(__('routing.notify.pull_in_recurring', [], 'en'))->not->toBe('routing.notify.pull_in_recurring')
        ->and(__('routing.notify.pull_in_recurring', [], 'am'))->not->toBe('routing.notify.pull_in_recurring');
});

it('manual end: the Doc 03 override is removed THROUGH its door (rbac.override.removed), both rows end jointly, routing.pull_in.ended{ended_by_staff} + queue.temporary_grant.expired{ended_by_staff}, timer member dropped', function () {
    [$tenantId, $payments, $queue, $granter] = pullInScene();
    $agent = s7Actor(['tickets.view'], 'Dept', 'S7 Ended Agent');
    $result = app(PullIn::class)->create($granter, $queue, $agent->identifier(), null, 'Manual-end fixture reason.');

    app(PullIn::class)->end($granter, $queue, $agent->identifier());

    expect(DB::table('user_permission_overrides')->where('id', $result['override_id'])->value('revoke_cause'))->toBe('removed')
        ->and(DB::table('audit_log')->where('action_key', 'rbac.override.removed')->where('entity_id', $agent->identifier())->count())->toBe(1)
        ->and(DB::table('queue_members')->where('override_id', $result['override_id'])->whereNull('ended_at')->count())->toBe(0)
        ->and(DB::table('user_departments')->where('override_id', $result['override_id'])->whereNull('ended_at')->count())->toBe(0)
        ->and(app(EffectiveMembers::class)->eligible($queue))->not->toContain($agent->identifier())
        ->and(app(ExpiryZset::class)->has($tenantId, $result['override_id']))->toBeFalse();
    $audit = DB::table('audit_log')->where('action_key', 'routing.pull_in.ended')->orderByDesc('id')->first(['actor_type', 'actor_id', 'payload']);
    expect($audit->actor_type)->toBe('staff')->and($audit->actor_id)->toBe($granter->identifier())->and(json_decode((string) $audit->payload, true)['cause'])->toBe('ended_by_staff');
    $event = json_decode((string) DB::table('outbox')->where('name', 'queue.temporary_grant.expired')->orderByDesc('id')->value('payload'), true);
    expect($event['cause'])->toBe('ended_by_staff')->and($event['override_id'])->toBe($result['override_id']);
    expect(fn () => app(PullIn::class)->end($granter, $queue, $agent->identifier()))->toThrow(RoutingRefused::class, 'pull_in_not_found');
});

it('order-independence with Doc 03\'s tidy (rbac:expire-overrides ends the rows first) — the routing sweep then finds nothing live: dropped, no duplicate event; and routing:rebuild restores the timer member from the tables (FS-01)', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $granter = s7Actor(['tickets.view', 'org.queues.pull_in'], 'All', 'S7 Order Granter');
    $agent = s7Actor(['tickets.view'], 'All', 'S7 Order Agent');
    $live = s7Actor(['tickets.view'], 'All', 'S7 Order Live Agent');
    $expired = expiredPullInRows($tenantId, $queue, $payments, $agent, $granter);
    $liveOverride = expiredPullInRows($tenantId, $queue, $payments, $live, $granter, "clock_timestamp() + interval '2 hours'");
    app(ExpiryZset::class)->add($tenantId, $expired, 1);
    app(ExpiryZset::class)->remove($tenantId, $liveOverride);
    commitFixtures();

    try {
        app(TenantContext::class)->clear();
        app(App\Support\Tenancy\RlsSession::class)->clear();
        Illuminate\Support\Facades\Artisan::call('rbac:expire-overrides');
        asTenant($tenant, function () use ($tenantId, $expired, $liveOverride): void {
            expect(DB::table('queue_members')->where('override_id', $expired)->whereNull('ended_at')->count())->toBe(0, 'precondition: the Doc 03 tidy ended the rows');
            $before = DB::table('outbox')->where('name', 'queue.temporary_grant.expired')->count();
            expect(app(ExpirySweep::class)->sweepOne($tenantId, $expired))->toBe('dropped')
                ->and(DB::table('outbox')->where('name', 'queue.temporary_grant.expired')->count())->toBe($before, 'a duplicate expiry event was published')
                ->and(app(ExpiryZset::class)->has($tenantId, $expired))->toBeFalse();

            // FS-01: the member for the still-live pull-in is restored from the tables (tenant-bound, like the command's other halves).
            expect(app(ExpiryZset::class)->has($tenantId, $liveOverride))->toBeFalse('precondition');
            Illuminate\Support\Facades\Artisan::call('routing:rebuild');
            expect(app(ExpiryZset::class)->has($tenantId, $liveOverride))->toBeTrue('routing:rebuild did not restore the expiry member')
                ->and(app(ExpiryZset::class)->has($tenantId, $expired))->toBeFalse('an ended pull-in was resurrected into the timer set');
        });
    } finally {
        app(TenantContext::class)->clear();
        app(App\Support\Tenancy\RlsSession::class)->clear();
        cleanupTenants([$tenantId]);
        DB::connection('pgsql')->beginTransaction();
    }
});
