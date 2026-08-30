<?php

declare(strict_types=1);

use App\Domain\Org\Membership\EffectiveMembers;
use App\Domain\Org\Structure\QueueWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-04 — effective members, and A-50 obligation 1: INDEPENDENCE + the J04 config error
|--------------------------------------------------------------------------
| Fixtures hold (a) a user WITH a user_departments row and NO queue membership — not a member
| at all (department access alone routes nothing), and (b) a Dept-scoped user WITH queue
| membership and NO user_departments row — a MEMBER whose scope cannot reach the queue's
| department: FR-04's CONFIG ERROR, excluded from eligibility and badged with the
| department named. Own/All-scoped members always reach (A-11); Team-scoped reach through
| queue_teams; a temporary member counts while unexpired.
*/

it('A-50 obligation 1: department access without queue membership is NOT membership; queue membership without department access is a CONFIG ERROR — badged, excluded (the J04 case)', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $department = departmentFixture('Verification');
    $queue = (string) DB::table('departments')->where('id', $department)->value('default_queue_id');
    $admin = ticketActor(['org.queues.manage'], 'Fixture Queue Admin');

    $accessOnly = scopedActor(['tickets.view'], 'Dept', 'Fixture Dept Access Only');
    DB::table('user_departments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'user_id' => $accessOnly->identifier(), 'department_id' => $department, 'kind' => 'direct']);

    $memberNoAccess = scopedActor(['tickets.view'], 'Dept', 'Fixture Dept Member No Access');
    app(QueueWriter::class)->addMember($queue, $memberNoAccess->identifier(), $admin->identifier());

    $ownMember = scopedActor(['tickets.view'], 'Own', 'Fixture Own Member');
    app(QueueWriter::class)->addMember($queue, $ownMember->identifier(), $admin->identifier());

    $members = app(EffectiveMembers::class)->members($queue);
    $byUser = array_column($members, null, 'user_id');

    expect(array_key_exists($accessOnly->identifier(), $byUser))->toBeFalse('department access alone became queue membership');
    expect($byUser[$memberNoAccess->identifier()]['config_error'])->toBeTrue('a Dept-scoped member with no access to the department was not flagged (FR-04/J04)')
        ->and($byUser[$memberNoAccess->identifier()]['unreachable_department_id'])->toBe($department)
        ->and($byUser[$ownMember->identifier()]['config_error'])->toBeFalse('an Own-scoped member reaches by assignment (A-11)');
    expect(app(EffectiveMembers::class)->eligible($queue))->toBe([$ownMember->identifier()]);
});

it('FR-04: effective agents = direct ∪ via-team ∪ unexpired temporary, with provenance; expired temporary and ended rows drop out', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $department = departmentFixture('Payments');
    $queue = (string) DB::table('departments')->where('id', $department)->value('default_queue_id');
    $admin = ticketActor(['org.queues.manage', 'org.teams.manage'], 'Fixture Org Admin');

    $direct = ticketActor(['tickets.view'], 'Fixture Direct');           // All scope
    app(QueueWriter::class)->addMember($queue, $direct->identifier(), $admin->identifier());

    $viaTeam = ticketActor(['tickets.view'], 'Fixture Via Team');
    $team = app(App\Domain\Org\Structure\TeamWriter::class)->create('Night shift', $admin->identifier());
    app(App\Domain\Org\Structure\TeamWriter::class)->addMember($team, $viaTeam->identifier(), $admin->identifier());
    app(QueueWriter::class)->addTeam($queue, $team, $admin->identifier());

    $mkOverride = function (string $userId, string $expires) use ($tenantId): string {
        $id = (string) Str::uuid7();
        DB::table('user_permission_overrides')->insert(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'permission' => 'org.queues.pull_in', 'effect' => 'allow', 'reason' => 'effective members fixture', 'granted_by' => $userId, 'expires_at' => DB::raw($expires)]);

        return $id;
    };
    $temp = ticketActor(['tickets.view'], 'Fixture Temp');
    DB::table('queue_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'queue_id' => $queue, 'user_id' => $temp->identifier(), 'kind' => 'temporary', 'override_id' => $mkOverride($temp->identifier(), "clock_timestamp() + interval '1 hour'"), 'expires_at' => DB::raw("clock_timestamp() + interval '1 hour'")]);
    $expired = ticketActor(['tickets.view'], 'Fixture Expired');
    DB::table('queue_members')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'queue_id' => $queue, 'user_id' => $expired->identifier(), 'kind' => 'temporary', 'override_id' => $mkOverride($expired->identifier(), "clock_timestamp() - interval '1 minute'"), 'expires_at' => DB::raw("clock_timestamp() - interval '1 minute'")]);
    $ended = ticketActor(['tickets.view'], 'Fixture Ended');
    app(QueueWriter::class)->addMember($queue, $ended->identifier(), $admin->identifier());
    app(QueueWriter::class)->removeMember($queue, $ended->identifier(), $admin->identifier());

    $members = array_column(app(EffectiveMembers::class)->members($queue), 'via', 'user_id');
    ksort($members);
    $expected = [$direct->identifier() => 'direct', $temp->identifier() => 'temporary', $viaTeam->identifier() => 'team'];
    ksort($expected);
    expect($members)->toBe($expected);
    expect(array_key_exists($expired->identifier(), $members))->toBeFalse('an expired temporary member is still effective')
        ->and(array_key_exists($ended->identifier(), $members))->toBeFalse('an ended member is still effective');
    expect(DB::table('queue_members')->where('user_id', $ended->identifier())->count())->toBe(1, 'FLAG C: the ended row was deleted');
});
