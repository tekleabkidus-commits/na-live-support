<?php

declare(strict_types=1);

use App\Domain\Org\Membership\DepartmentAccess;
use App\Domain\Org\Membership\OrgMembership;
use App\Domain\Org\OrgRefused;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-04 / A-50 point 5 — obligation 3: J04's "Give access" is ONE ACT
|--------------------------------------------------------------------------
| One transaction writes the user_departments row AND the default-queue membership under a
| single org.department.access_granted audit action; a partial write is IMPOSSIBLE — proven
| by breaking the second insert (an FK violation) and asserting the first is gone too.
*/

it('grants department access AND default-queue membership in ONE transaction under ONE audit action', function () {
    createTenant();
    $department = departmentFixture('Verification');
    $queue = (string) DB::table('departments')->where('id', $department)->value('default_queue_id');
    $agent = ticketActor(['tickets.view'], 'Fixture Access Agent');
    $admin = ticketActor(['org.departments.manage'], 'Fixture Access Admin');

    $result = app(DepartmentAccess::class)->grant($agent->identifier(), $department, $admin->identifier());

    expect($result)->toBe(['department_id' => $department, 'queue_id' => $queue]);
    expect(DB::table('user_departments')->where('user_id', $agent->identifier())->where('department_id', $department)->whereNull('ended_at')->count())->toBe(1)
        ->and(DB::table('queue_members')->where('user_id', $agent->identifier())->where('queue_id', $queue)->where('kind', 'direct')->whereNull('ended_at')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'org.department.access_granted')->count())->toBe(1)
        ->and(DB::table('audit_log')->whereIn('action_key', ['org.queue.member_added'])->count())->toBe(0, 'a second audit action for the same act');
    expect(app(OrgMembership::class)->departmentIdsFor($agent))->toBe([$department]);

    // Idempotent: a second grant adds nothing and audits the act again (an act is an act).
    app(DepartmentAccess::class)->grant($agent->identifier(), $department, $admin->identifier());
    expect(DB::table('user_departments')->where('user_id', $agent->identifier())->count())->toBe(1);
});

it('a partial write is IMPOSSIBLE: a failure at the queue half rolls the department half back with it (one transaction, proven)', function () {
    createTenant();
    $department = departmentFixture('Payments');
    $agent = ticketActor(['tickets.view'], 'Fixture Access Agent');
    $admin = ticketActor(['org.departments.manage'], 'Fixture Access Admin');

    // Break the act at the SECOND write: a query listener throws right after the queue_members
    // INSERT executes — inside the door's transaction — so the rollback must undo the first write too.
    $armed = true;
    DB::listen(function (Illuminate\Database\Events\QueryExecuted $q) use (&$armed): void {
        if ($armed && str_contains($q->sql, 'insert into "queue_members"')) {
            $armed = false;
            throw new RuntimeException('queue half failed (fixture)');
        }
    });

    expect(fn () => app(DepartmentAccess::class)->grant($agent->identifier(), $department, $admin->identifier()))->toThrow(RuntimeException::class, 'queue half failed');
    $armed = false;
    expect(DB::table('user_departments')->where('user_id', $agent->identifier())->count())->toBe(0, 'the department half survived a failed queue half — the grant is not one transaction')
        ->and(DB::table('queue_members')->where('user_id', $agent->identifier())->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action_key', 'org.department.access_granted')->count())->toBe(0);
});

it('revoke ENDS both direct rows (never deletes) under org.department.access_revoked', function () {
    createTenant();
    $department = departmentFixture('Bonuses');
    $agent = ticketActor(['tickets.view'], 'Fixture Access Agent');
    $admin = ticketActor(['org.departments.manage'], 'Fixture Access Admin');
    app(DepartmentAccess::class)->grant($agent->identifier(), $department, $admin->identifier());
    app(DepartmentAccess::class)->revoke($agent->identifier(), $department, $admin->identifier());

    expect(DB::table('user_departments')->where('user_id', $agent->identifier())->whereNotNull('ended_at')->count())->toBe(1)
        ->and(DB::table('queue_members')->where('user_id', $agent->identifier())->whereNotNull('ended_at')->count())->toBe(1)
        ->and(app(OrgMembership::class)->departmentIdsFor($agent))->toBe([])
        ->and(DB::table('audit_log')->where('action_key', 'org.department.access_revoked')->count())->toBe(1);
});

it('refuses a department without a default queue (EC-10) — nothing written', function () {
    createTenant();
    $department = departmentFixture('Technical');
    // The fixture row is uncommitted inside the test transaction — clear the default on the app
    // connection (app holds UPDATE on default_queue_id), not the platform one (which cannot see it).
    DB::table('departments')->where('id', $department)->update(['default_queue_id' => null]);
    $agent = ticketActor(['tickets.view'], 'Fixture Access Agent');
    expect(fn () => app(DepartmentAccess::class)->grant($agent->identifier(), $department, $agent->identifier()))->toThrow(OrgRefused::class);
    expect(DB::table('user_departments')->count())->toBe(0);
});
