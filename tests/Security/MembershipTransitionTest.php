<?php

declare(strict_types=1);

use App\Domain\Org\Membership\OrgMembership;
use App\Models\User;
use App\Support\Rbac\Scopes\EmptyMembership;
use App\Support\Rbac\Scopes\MembershipSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-04 / T-01 / Doc 03 T-03 / A-50 — THE SCOPE-WIDENING MOMENT, PROVEN DELIBERATELY
|--------------------------------------------------------------------------
|
| S11 plan step 1(ii): "the moment you bind the membership source, every Team/Dept-scoped
| permission starts resolving to real rows platform-wide. Prove the transition BEFORE any
| routing path exists: fail-closed empty pre-binding; EXACTLY the expected member sets
| post-binding (direct ∪ via-team ∪ unexpired temporary, ∩ department access); a foreign or
| unexpected row appearing anywhere = red. A scope that silently widens is a security
| event, not a bug."
|
| The real tables are POPULATED in both halves — the PRE half proves the old binding
| ignores them (fail-closed), the POST half proves the new binding reads exactly them.
| Sabotage #1 poisons departmentIdsFor() with a foreign id → the named line goes red.
*/

/**
 * A tenant with two departments (D1, D2), a team T1 whose queue sits in D1, users:
 *   direct   — user_departments D1 (direct)
 *   viaTeam  — team_members T1 (reach: D1 through T1's queue), no user_departments
 *   temp     — user_departments D2 (temporary, unexpired, override-linked)
 *   expired  — user_departments D2 (temporary, EXPIRED)
 *   ended    — user_departments D1 (direct, ended_at set)
 *   nobody   — no rows
 * plus a FOREIGN tenant whose own rows must never appear.
 *
 * @return array{tenant: App\Models\Tenant, users: array<string, User>, d1: string, d2: string, t1: string}
 */
function orgMembershipScene(): array
{
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $d1 = departmentFixture('Payments');
    $d2 = departmentFixture('Verification');
    $q1 = (string) DB::table('departments')->where('id', $d1)->value('default_queue_id');
    $t1 = (string) Str::uuid7();
    DB::table('teams')->insert(['id' => $t1, 'tenant_id' => $tenantId, 'name' => 'Night shift']);
    DB::table('queue_teams')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'queue_id' => $q1, 'team_id' => $t1]);

    $mk = fn (string $name): User => ticketActor(['tickets.view'], "Fixture Membership {$name}");
    $users = ['direct' => $mk('direct'), 'viaTeam' => $mk('viaTeam'), 'temp' => $mk('temp'), 'expired' => $mk('expired'), 'ended' => $mk('ended'), 'nobody' => $mk('nobody')];

    $override = function (User $user, string $expiresSql) use ($tenantId): string {
        $id = (string) Str::uuid7();
        DB::table('user_permission_overrides')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $user->identifier(), 'permission' => 'org.queues.pull_in',
            'effect' => 'allow', 'reason' => 'membership transition fixture', 'granted_by' => $user->identifier(),
            'expires_at' => DB::raw($expiresSql),
        ]);

        return $id;
    };

    DB::table('user_departments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $users['direct']->identifier(), 'department_id' => $d1, 'kind' => 'direct']);
    DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'team_id' => $t1, 'user_id' => $users['viaTeam']->identifier()]);
    $liveOverride = $override($users['temp'], "clock_timestamp() + interval '2 hours'");
    DB::table('user_departments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $users['temp']->identifier(), 'department_id' => $d2, 'kind' => 'temporary', 'override_id' => $liveOverride, 'expires_at' => DB::raw("clock_timestamp() + interval '2 hours'")]);
    $deadOverride = $override($users['expired'], "clock_timestamp() - interval '1 minute'");
    DB::table('user_departments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $users['expired']->identifier(), 'department_id' => $d2, 'kind' => 'temporary', 'override_id' => $deadOverride, 'expires_at' => DB::raw("clock_timestamp() - interval '1 minute'")]);
    $endedId = (string) Str::uuid7();
    DB::table('user_departments')->insert(['id' => $endedId, 'tenant_id' => $tenantId, 'user_id' => $users['ended']->identifier(), 'department_id' => $d1, 'kind' => 'direct']);
    DB::table('user_departments')->where('id', $endedId)->update(['ended_at' => DB::raw('clock_timestamp()')]);

    // The FOREIGN tenant: its own D1-lookalike and a direct membership for a user of ITS tenant.
    $home = $tenantId;
    $foreign = createTenant();
    $fd = departmentFixture('Payments');
    $fu = ticketActor(['tickets.view'], 'Fixture Foreign Member');
    DB::table('user_departments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $foreign->identifier(), 'user_id' => $fu->identifier(), 'department_id' => $fd, 'kind' => 'direct']);
    bindTenant($home);

    return ['tenant' => $tenant, 'users' => $users, 'd1' => $d1, 'd2' => $d2, 't1' => $t1];
}

it('PRE-BINDING: with the real tables POPULATED, EmptyMembership still yields [] for every user at every level — fail-closed is not "empty because empty"', function () {
    $scene = orgMembershipScene();
    $source = new EmptyMembership;
    foreach ($scene['users'] as $name => $user) {
        expect($source->teamIdsFor($user))->toBe([], "pre-binding team ids leaked for {$name}")
            ->and($source->departmentIdsFor($user))->toBe([], "pre-binding department ids leaked for {$name}")
            ->and($source->teamReachDepartmentIdsFor($user))->toBe([], "pre-binding reach leaked for {$name}");
    }
    expect(DB::table('user_departments')->count())->toBeGreaterThan(0, 'the pre half would be vacuous');
});

it('POST-BINDING: OrgMembership yields EXACTLY the expected sets — direct, via-team reach, unexpired temporary; expired, ended, foreign and nobody yield nothing', function () {
    $scene = orgMembershipScene();
    $u = $scene['users'];
    $source = app(OrgMembership::class);

    expect($source->departmentIdsFor($u['direct']))->toBe([$scene['d1']]);
    expect($source->teamIdsFor($u['viaTeam']))->toBe([$scene['t1']])
        ->and($source->teamReachDepartmentIdsFor($u['viaTeam']))->toBe([$scene['d1']])
        ->and($source->departmentIdsFor($u['viaTeam']))->toBe([], 'team membership is NOT department access (A-50 point 1)');
    expect($source->departmentIdsFor($u['temp']))->toBe([$scene['d2']]);
    // THE NAMED LINES — a widening lands here:
    expect($source->departmentIdsFor($u['expired']))->toBe([], 'an EXPIRED temporary access resolved — the resolver time check is gone (T-07/FR-09)');
    expect($source->departmentIdsFor($u['ended']))->toBe([], 'an ENDED access resolved');
    expect($source->departmentIdsFor($u['nobody']))->toBe([])->and($source->teamIdsFor($u['nobody']))->toBe([]);
    foreach ($u as $name => $user) {
        foreach ($source->departmentIdsFor($user) as $id) {
            // (toContain treats every argument as a needle — the first run's own catch.)
            expect(in_array($id, [$scene['d1'], $scene['d2']], true))->toBeTrue("a FOREIGN or unknown department id resolved for {$name}: {$id}");
        }
    }
});

it('the production container binds OrgMembership (the seam is real), and the test-only EmptyMembership remains the fail-closed control', function () {
    expect(app(MembershipSource::class))->toBeInstanceOf(OrgMembership::class);
    expect((new EmptyMembership)->departmentIdsFor(User::factory()->make()))->toBe([]);
});

it('A-50 obligation 2 — DIVERGENCE: a teammate ASSIGNED a ticket in a department OUTSIDE the team\'s queue reach is NOT in Team scope (point 3 stays visible and testable)', function () {
    $scene = orgMembershipScene();
    $viaTeam = $scene['users']['viaTeam'];
    $teammate = ticketActor(['tickets.view'], 'Fixture Teammate');
    DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $scene['tenant']->identifier(), 'team_id' => $scene['t1'], 'user_id' => $teammate->identifier()]);

    // Two tickets IN THE SCENE'S TENANT: one in D1 (inside T1's reach), one in D2 (outside),
    // both ASSIGNED to the teammate. (The first run re-homed ticketFixture() rows from other
    // tenants through the platform connection — a split graph; built natively instead.)
    $tenantId = $scene['tenant']->identifier();
    $customer = App\Models\Customer::query()->create(['display_name' => 'Fixture Divergence', 'username' => '@fixture_div_'.Str::random(6), 'is_shell' => true, 'created_source' => 'staff']);
    $mkTicket = function (string $departmentId) use ($tenantId, $customer, $teammate): App\Models\Ticket {
        $conversationId = (string) Str::uuid7();
        DB::table('conversations')->insert(['id' => $conversationId, 'tenant_id' => $tenantId, 'channel' => 'fakechan', 'channel_ref_id' => 'bot-1', 'external_chat_id' => 'chat-'.Str::random(10), 'customer_id' => $customer->identifier(), 'state' => 'active']);
        $ticketId = (string) Str::uuid7();
        DB::table('tickets')->insert(['id' => $ticketId, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'customer_id' => $customer->identifier(), 'number' => random_int(1, 2_000_000_000), 'status' => 'open', 'priority' => 'normal', 'source' => 'agent', 'department_id' => $departmentId, 'assignee_id' => $teammate->identifier()]);

        return App\Models\Ticket::query()->findOrFail($ticketId);
    };
    $inside = $mkTicket($scene['d1']);
    $outside = $mkTicket($scene['d2']);
    bindTenant($scene['tenant']);
    $teamScoped = scopedActor(['tickets.view'], 'Team', 'Fixture Team-Scoped Viewer');
    DB::table('team_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $scene['tenant']->identifier(), 'team_id' => $scene['t1'], 'user_id' => $teamScoped->identifier()]);

    $scope = app(App\Domain\Tickets\Storage\TicketScope::class);
    expect($scope->visible($teamScoped, $inside->identifier()))->toBeTrue('a ticket in the team\'s reach is invisible — the reach did not widen')
        ->and($scope->visible($teamScoped, $outside->identifier()))->toBeFalse('DIVERGENCE: a teammate\'s ticket OUTSIDE the team\'s queue reach entered Team scope — point 3 drifted');
    unset($viaTeam);
});

it('obligation 6 — the production WIRING binds OrgMembership, and EmptyMembership never returns silently (static + runtime)', function () {
    $provider = (string) file_get_contents(base_path('app/Providers/AppServiceProvider.php'));
    $code = '';
    foreach (token_get_all($provider) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
    expect($code)->toContain('OrgMembership::class')
        ->and($code)->not->toContain('EmptyMembership::class', 'the fail-closed seam is wired back into production');
    app()->forgetInstance(MembershipSource::class);
    expect(app(MembershipSource::class))->toBeInstanceOf(OrgMembership::class);
});
