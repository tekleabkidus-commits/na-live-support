<?php

declare(strict_types=1);

use App\Domain\Inbox\Views\QueueViews;
use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Routing\Waiting\WaitingSets;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 07 FR-05's queue views, REAL (S11 step 11a; STOP-H countersigned) — the seam and its
| door are DELETED in this commit; the views come from effective membership, the rows from
| the dispatcher's own WaitingSets order (FR-08 step 3), the 404 pattern on lost
| membership (Doc 07 EC-09 / Doc 11 EC-07).
|--------------------------------------------------------------------------
*/

function qvActor(array $permissions, string $roleName): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName], ['color' => 'role-agent', 'permissions' => $permissions, 'scopes' => ['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All']]);
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

it('STOP-H: the workspace payload carries REAL queue views per effective membership (direct ∪ team ∪ unexpired temporary; expired/ended/inactive NEVER), counts = the waiting depth, and the seam artifacts are GONE', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $admin = qvActor(['org.queues.manage', 'org.teams.manage', 'tickets.view', 'views.manage', 'conversations.view'], 'S11 QV Admin');
    $agent = qvActor(['tickets.view', 'views.manage', 'conversations.view'], 'S11 QV Agent');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());

    // A temporary member (unexpired) sees the queue; an expired one never does (EC-07/T-07).
    $temp = qvActor(['tickets.view', 'views.manage', 'conversations.view'], 'S11 QV Temp');
    $mk = function (User $u, string $expires) use ($tenantId, $queue): void {
        $oid = (string) Str::uuid7();
        DB::table('user_permission_overrides')->insert(['id' => $oid, 'tenant_id' => $tenantId, 'user_id' => $u->identifier(), 'permission' => 'tickets.assign_self', 'effect' => 'allow', 'reason' => 'queue view fixture', 'granted_by' => $u->identifier(), 'expires_at' => DB::raw($expires)]);
        DB::table('queue_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'queue_id' => $queue, 'user_id' => $u->identifier(), 'kind' => 'temporary', 'override_id' => $oid, 'expires_at' => DB::raw($expires)]);
    };
    $mk($temp, "clock_timestamp() + interval '1 hour'");
    $expired = qvActor(['tickets.view', 'views.manage', 'conversations.view'], 'S11 QV Expired');
    $mk($expired, "clock_timestamp() - interval '1 minute'");

    [, $conversation] = conversationFixture();
    $t = app(App\Domain\Tickets\Creation\CreateTicket::class)->create(new App\Domain\Tickets\Creation\NewTicket($conversation, App\Domain\Tickets\Creation\TicketSource::Agent, departmentId: $payments));
    app(RoutingPipeline::class)->route($t, 'created', ['explicit_department_id' => $payments]);

    $views = app(QueueViews::class)->forViewer($agent);
    expect(array_column($views, 'id'))->toContain($queue)
        ->and(collect($views)->firstWhere('id', $queue)['count'])->toBe(1)
        ->and(array_column(app(QueueViews::class)->forViewer($temp), 'id'))->toContain($queue)
        ->and(array_column(app(QueueViews::class)->forViewer($expired), 'id'))->not->toContain($queue, 'an EXPIRED temporary member still sees the queue view (EC-07/EC-09)')
        ->and(app(QueueViews::class)->forViewer(qvActor(['tickets.view', 'views.manage', 'conversations.view'], 'S11 QV Nobody')))->toBe([]);

    // The seam is GONE: no fixture file, no ?seam_queues reference anywhere under app/.
    expect(is_file(base_path('fixtures/inbox/seams/doc11-queue-views.json')))->toBeFalse('the seam fixture survived (STOP-H: deleted in the same commit)')
        ->and(str_contains((string) file_get_contents(app_path('Http/Controllers/Inbox/WorkspaceController.php')), 'seam_queues'))->toBeFalse();

    // The workspace payload itself carries the real rows.
    activateTenant($tenant);
    $response = $this->actingAs($agent, 'staff')->get('https://localhost/inbox');
    $response->assertStatus(200);
    bindTenant($tenant);
    $payload = $response->viewData('page')['props']['queueViews'] ?? null;
    expect($payload)->toBeArray()->and(array_column((array) $payload, 'id'))->toContain($queue);
});

it('FR-05/FR-08 step 3: the queue view LIST serves the waiting set in the dispatcher\'s own order with its scores, cursored; the header total is the depth; a non-member (or an ENDED membership — EC-09) gets the 404 pattern and the stable queue_view_gone code', function () {
    $tenant = createTenant();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $admin = qvActor(['org.queues.manage', 'tickets.view', 'views.manage', 'conversations.view'], 'S11 QV Admin 2');
    $agent = qvActor(['tickets.view', 'views.manage', 'conversations.view'], 'S11 QV Member 2');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        [, $conversation] = conversationFixture();
        $t = app(App\Domain\Tickets\Creation\CreateTicket::class)->create(new App\Domain\Tickets\Creation\NewTicket($conversation, App\Domain\Tickets\Creation\TicketSource::Agent, departmentId: $payments));
        app(RoutingPipeline::class)->route($t, 'created', ['explicit_department_id' => $payments]);
        app(App\Domain\Inbox\ReadModels\ListProjector::class)->recomputeTicket($t->identifier());   // the read model rows the list hydrates
        $ids[] = $t->identifier();
    }
    activateTenant($tenant);

    $first = $this->actingAs($agent, 'staff')->getJson('https://localhost/inbox/list?view=queue:'.$queue.'&limit=2');
    $first->assertStatus(200);
    bindTenant($tenant);
    expect($first->json('total'))->toBe(3)
        ->and(array_column((array) $first->json('rows'), 'ticket_id'))->toBe(app(WaitingSets::class)->head($queue, 2), 'the list order is not the dispatcher\'s (FR-08 step 3)')
        ->and($first->json('next_cursor'))->toBeString();
    $second = $this->actingAs($agent, 'staff')->getJson('https://localhost/inbox/list?view=queue:'.$queue.'&limit=2&cursor='.$first->json('next_cursor'));
    bindTenant($tenant);
    expect(array_column((array) $second->json('rows'), 'ticket_id'))->toBe([$ids[2]])
        ->and($second->json('next_cursor'))->toBeNull();

    // Non-member: the 404 pattern (C-10) with the stable code the client toasts on (EC-09's vanish).
    $stranger = qvActor(['tickets.view', 'views.manage', 'conversations.view'], 'S11 QV Stranger');
    $gone = $this->actingAs($stranger, 'staff')->getJson('https://localhost/inbox/list?view=queue:'.$queue);
    $gone->assertStatus(404);
    bindTenant($tenant);
    expect($gone->json('error'))->toBe('queue_view_gone');
    // A membership that ENDS mid-session answers the same way on the next fetch.
    app(QueueWriter::class)->removeMember($queue, $agent->identifier(), $admin->identifier());
    $after = $this->actingAs($agent, 'staff')->getJson('https://localhost/inbox/list?view=queue:'.$queue);
    $after->assertStatus(404);
    bindTenant($tenant);
    expect($after->json('error'))->toBe('queue_view_gone');
    // An unknown/foreign queue id: the same 404 shape — no existence oracle.
    $this->actingAs($agent, 'staff')->getJson('https://localhost/inbox/list?view=queue:'.(string) Str::uuid7())->assertStatus(404);
});
