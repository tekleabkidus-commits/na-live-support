<?php

declare(strict_types=1);

use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Org\Structure\TeamWriter;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 11 §8 — Backoffice → Organization, the SERVER side (S11 step 12; B5-S09 the frames).
| The payload is a PREVIEW of what will happen (the real rotation, the real breakdown, the
| real bands); mutations reuse the step-3b writers; J20's conflict is compare-and-refuse.
|--------------------------------------------------------------------------
*/

function orgScreenActor(array $permissions, string $roleName = 'S12 Org Admin'): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName], ['color' => 'role-agent', 'permissions' => $permissions, 'scopes' => ['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All']]);
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

it('§8: the Organization payload carries the three tabs\' truth — ordered departments with default badges and hours refs, teams with role-context chips and the J06 SPECIFIC warning, queues with the REAL rotation, provenance, breakdown and bands; the read door needs org.queues.manage', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $admin = orgScreenActor(['org.departments.manage', 'org.teams.manage', 'org.queues.manage', 'org.queues.pull_in', 'tickets.view']);

    // A team whose member has NO access to Payments (the J06 warning shape), serving the payments queue.
    $blind = orgScreenActor(['tickets.view'], 'S12 Team Blind Dept');
    Role::query()->where('name', 'S12 Team Blind Dept')->update(['scopes' => json_encode(['tickets' => 'Dept', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All'])]);
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($blind);
    $team = app(TeamWriter::class)->create('Night shift', $admin->identifier());
    app(TeamWriter::class)->addMember($team, $blind->identifier(), $admin->identifier());
    app(QueueWriter::class)->addTeam($queue, $team, $admin->identifier());
    // A temporary member with a future expiry (the dashed chip).
    $temp = orgScreenActor(['tickets.view'], 'S12 Temp Member');
    $oid = (string) Str::uuid7();
    DB::table('user_permission_overrides')->insert(['id' => $oid, 'tenant_id' => $tenantId, 'user_id' => $temp->identifier(), 'permission' => 'tickets.assign_self', 'effect' => 'allow', 'reason' => 'org screen fixture', 'granted_by' => $temp->identifier(), 'expires_at' => DB::raw("clock_timestamp() + interval '2 hours'")]);
    DB::table('queue_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'queue_id' => $queue, 'user_id' => $temp->identifier(), 'kind' => 'temporary', 'override_id' => $oid, 'expires_at' => DB::raw("clock_timestamp() + interval '2 hours'")]);

    activateTenant($tenant);
    $response = $this->actingAs($admin, 'staff')->get('https://localhost/admin/organization');
    $response->assertStatus(200);
    bindTenant($tenant);
    $props = $response->viewData('page')['props'];
    expect($props['can']['org.queues.manage'])->toBeTrue();
    $departments = collect($props['departments']);
    expect($departments->pluck('position')->all())->toBe($departments->pluck('position')->sort()->values()->all(), 'departments are not position-ordered');
    $paymentsRow = $departments->firstWhere('slug', 'payments');
    expect($paymentsRow['default_queue_name'])->not->toBeNull()->and($paymentsRow['queue_count'])->toBeGreaterThan(0);
    $teamRow = collect($props['teams'])->firstWhere('name', 'Night shift');
    expect($teamRow)->not->toBeNull();
    $memberRow = collect($teamRow['members'])->firstWhere('user_id', $blind->identifier());
    expect($memberRow['warnings'])->toHaveCount(1, 'the J06 warning must name the unreachable department')
        ->and($memberRow['warnings'][0]['department'])->toBe('Payments');
    $queueRow = collect($props['queues'])->firstWhere('id', $queue);
    expect($queueRow['strategy'])->toBe('round_robin')
        ->and(collect($queueRow['members'])->firstWhere('user_id', $temp->identifier())['via'])->toBe('temporary')
        ->and($queueRow['breakdown']['members'])->toBeGreaterThanOrEqual(2)
        ->and($queueRow['estimate'])->toHaveKeys(['band', 'degraded'])
        ->and(array_keys($queueRow['estimate']))->toBe(['band', 'degraded']);

    // The read door is gated.
    $nobody = orgScreenActor(['tickets.view'], 'S12 Nobody');
    $this->actingAs($nobody, 'staff')->get('https://localhost/admin/organization')->assertStatus(403);
    bindTenant($tenant);
});

it('J20: a stale expected_updated_at is REFUSED 409 edit_conflict with THEIR stamp (nothing overwritten); "Keep mine" (no guard) saves over deliberately; the writers\' own audits ride the doors', function () {
    $tenant = createTenant();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $admin = orgScreenActor(['org.departments.manage', 'org.teams.manage', 'org.queues.manage', 'tickets.view'], 'S12 Conflict Admin');
    activateTenant($tenant);

    // Someone else edits first (the row's updated_at moves).
    app(QueueWriter::class)->update($queue, ['capacity_per_agent' => 4], $admin->identifier());
    $theirs = (string) DB::table('queues')->where('id', $queue)->value('updated_at');

    $stale = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/organization/queues/'.$queue, [
        'capacity_per_agent' => 7, 'expected_updated_at' => '2020-01-01 00:00:00+00',
    ]);
    $stale->assertStatus(409);
    bindTenant($tenant);
    expect($stale->json('refusal_code'))->toBe('edit_conflict')->and($stale->json('their_updated_at'))->toBe($theirs)
        ->and((int) DB::table('queues')->where('id', $queue)->value('capacity_per_agent'))->toBe(4, 'the stale save overwrote');

    $keep = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/organization/queues/'.$queue, ['capacity_per_agent' => 7]);
    $keep->assertStatus(200);
    bindTenant($tenant);
    expect((int) DB::table('queues')->where('id', $queue)->value('capacity_per_agent'))->toBe(7)
        ->and(DB::table('audit_log')->where('action_key', 'org.queue.updated')->count())->toBeGreaterThanOrEqual(2);

    // Department reorder + update through the doors (their audit declarations hold).
    $verification = (string) DB::table('departments')->where('slug', 'verification')->value('id');
    $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/organization/departments/'.$verification.'/reorder', ['position' => 0])->assertStatus(200);
    bindTenant($tenant);
    expect(DB::table('audit_log')->where('action_key', 'org.department.reordered')->count())->toBe(1);
});

it('J04: "Give access" grants department access through Doc 03\'s ONE-ACT door (org.department.access_granted) and the J06 warning clears on the next payload', function () {
    $tenant = createTenant();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $admin = orgScreenActor(['org.departments.manage', 'org.teams.manage', 'org.queues.manage', 'tickets.view'], 'S12 Access Admin');
    $blind = orgScreenActor(['tickets.view'], 'S12 Access Blind');
    Role::query()->where('name', 'S12 Access Blind')->update(['scopes' => json_encode(['tickets' => 'Dept', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All'])]);
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($blind);
    $team = app(TeamWriter::class)->create('Access team', $admin->identifier());
    app(TeamWriter::class)->addMember($team, $blind->identifier(), $admin->identifier());
    app(QueueWriter::class)->addTeam($queue, $team, $admin->identifier());
    activateTenant($tenant);

    $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/organization/departments/'.$payments.'/access', ['user_id' => $blind->identifier()])->assertStatus(200);
    bindTenant($tenant);
    expect(DB::table('audit_log')->where('action_key', 'org.department.access_granted')->count())->toBe(1)
        ->and(DB::table('user_departments')->where('user_id', $blind->identifier())->where('department_id', $payments)->whereNull('ended_at')->count())->toBe(1);

    $response = $this->actingAs($admin, 'staff')->get('https://localhost/admin/organization');
    bindTenant($tenant);
    $teamRow = collect($response->viewData('page')['props']['teams'])->firstWhere('name', 'Access team');
    $memberRow = collect($teamRow['members'])->firstWhere('user_id', $blind->identifier());
    expect($memberRow['warnings'])->toBe([], 'the warning must clear once access exists')
        ->and($memberRow['chips'])->toHaveCount(1);
});
