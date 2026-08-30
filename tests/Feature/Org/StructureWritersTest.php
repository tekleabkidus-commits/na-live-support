<?php

declare(strict_types=1);

use App\Domain\Org\OrgRefused;
use App\Domain\Org\Structure\DepartmentWriter;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Org\Structure\TeamWriter;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-01 / FR-02 / FR-03 / FR-18 / EC-05 / EC-10 / BR-01 / BR-04 — the structure writers
|--------------------------------------------------------------------------
*/

function orgAdmin(): App\Models\User
{
    return ticketActor(['org.departments.manage', 'org.teams.manage', 'org.queues.manage'], 'Fixture Org Admin');
}

it('FR-01/EC-10: a department is created WITH its default queue in one transaction; the default must be one of its own active queues; rename is free; reorder is a position', function () {
    createTenant();
    $admin = orgAdmin();
    $w = app(DepartmentWriter::class);
    $created = $w->create(['en' => 'Deposits', 'am' => 'ተቀማጭ'], 'deposits', $admin->identifier());
    expect(DB::table('departments')->where('id', $created['department_id'])->value('default_queue_id'))->toBe($created['queue_id']);

    $other = departmentFixture('Elsewhere');
    $foreignQueue = (string) DB::table('departments')->where('id', $other)->value('default_queue_id');
    expect(fn () => $w->update($created['department_id'], ['default_queue_id' => $foreignQueue], $admin->identifier()))->toThrow(OrgRefused::class);

    $w->update($created['department_id'], ['name' => ['en' => 'Money In', 'am' => 'ገንዘብ']], $admin->identifier());
    expect(json_decode((string) DB::table('departments')->where('id', $created['department_id'])->value('name'), true)['en'])->toBe('Money In');
    $w->reorder($created['department_id'], 7, $admin->identifier());
    expect((int) DB::table('departments')->where('id', $created['department_id'])->value('position'))->toBe(7);
    expect(DB::table('audit_log')->whereIn('action_key', ['org.department.created', 'org.queue.created', 'org.department.updated', 'org.department.reordered'])->count())->toBe(4);
    expect(fn () => $w->create(['am' => 'x'], 'no-en', $admin->identifier()))->toThrow(OrgRefused::class);
});

it('FR-03/BR-01/FLAG B: the boost list is ordered, known and duplicate-free — the starvation cap can never be an element; EC-05 warns when the VIP source is an agent-editable tag', function () {
    createTenant();
    $admin = orgAdmin();
    $department = departmentFixture('Payments');
    $queue = (string) DB::table('departments')->where('id', $department)->value('default_queue_id');
    $w = app(QueueWriter::class);

    expect($w->update($queue, ['priority_rules' => ['urgent_priority', 'vip', 'reopened']], $admin->identifier()))->toBe([]);
    expect(json_decode((string) DB::table('queues')->where('id', $queue)->value('priority_rules'), true))->toBe(['urgent_priority', 'vip', 'reopened']);
    expect(fn () => $w->update($queue, ['priority_rules' => ['urgent_priority', 'starvation']], $admin->identifier()))->toThrow(OrgRefused::class, 'starvation');
    expect(fn () => $w->update($queue, ['priority_rules' => ['vip', 'vip']], $admin->identifier()))->toThrow(OrgRefused::class);
    expect($w->update($queue, ['priority_rules' => ['vip'], 'vip_source' => 'tag'], $admin->identifier()))->toBe(['vip_source_warning']);
});

it('FR-03: is_rg is at most one per tenant — the second is refused with a stable code; strategy and fallback are closed enums; capacity is a positive int or null', function () {
    createTenant();
    $admin = orgAdmin();
    $a = departmentFixture('RG');
    $b = departmentFixture('Other');
    $qa = (string) DB::table('departments')->where('id', $a)->value('default_queue_id');
    $qb = (string) DB::table('departments')->where('id', $b)->value('default_queue_id');
    $w = app(QueueWriter::class);
    $w->update($qa, ['is_rg' => true], $admin->identifier());
    expect(fn () => $w->update($qb, ['is_rg' => true], $admin->identifier()))->toThrow(OrgRefused::class, 'queue_rg_already_exists');
    expect(fn () => $w->update($qb, ['assignment_strategy' => 'random'], $admin->identifier()))->toThrow(OrgRefused::class);
    expect(fn () => $w->update($qb, ['skill_fallback' => 'guess'], $admin->identifier()))->toThrow(OrgRefused::class);
    expect(fn () => $w->update($qb, ['capacity_per_agent' => 0], $admin->identifier()))->toThrow(OrgRefused::class);
    $w->update($qb, ['assignment_strategy' => 'load_based', 'capacity_per_agent' => 3, 'routing_tags' => ['kyc', 'የመለያ ማረጋገጫ']], $admin->identifier());
    $row = DB::table('queues')->where('id', $qb)->first();
    expect($row->assignment_strategy)->toBe('load_based')->and((int) $row->capacity_per_agent)->toBe(3)->and($row->routing_tags)->toContain('kyc');
    expect(DB::table('audit_log')->where('action_key', 'org.queue.strategy_changed')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'org.queue.tags_changed')->count())->toBe(1);
});

it('FR-18/EC-10: deactivating a department\'s DEFAULT queue is refused; deactivating another queue lists the rules falling to the default ([D12] seam)', function () {
    createTenant();
    $admin = orgAdmin();
    $department = departmentFixture('Deposits');
    $default = (string) DB::table('departments')->where('id', $department)->value('default_queue_id');
    $w = app(QueueWriter::class);
    expect(fn () => $w->deactivate($default, $admin->identifier()))->toThrow(OrgRefused::class, 'queue_is_department_default');
    $second = $w->create($department, 'Deposits — overflow', $admin->identifier());
    expect($w->deactivate($second, $admin->identifier()))->toBeArray();
    expect((bool) DB::table('queues')->where('id', $second)->value('is_active'))->toBeFalse();
});

it('FR-02: teams span departments through queues; members added/removed with audit; delete is a tombstone that ends the links', function () {
    createTenant();
    $admin = orgAdmin();
    $t = app(TeamWriter::class);
    $team = $t->create('Night shift', $admin->identifier());
    $agent = ticketActor(['tickets.view'], 'Fixture Agent');
    $t->addMember($team, $agent->identifier(), $admin->identifier(), isLead: true);
    $q1 = (string) DB::table('departments')->where('id', departmentFixture('Payments'))->value('default_queue_id');
    $q2 = (string) DB::table('departments')->where('id', departmentFixture('Verification'))->value('default_queue_id');
    app(QueueWriter::class)->addTeam($q1, $team, $admin->identifier());
    app(QueueWriter::class)->addTeam($q2, $team, $admin->identifier());
    expect(app(App\Domain\Org\Membership\OrgMembership::class)->teamReachDepartmentIdsFor($agent))->toHaveCount(2);
    expect(fn () => $t->update($team, ['name' => ' '], $admin->identifier()))->toThrow(OrgRefused::class);
    $t->removeMember($team, $agent->identifier(), $admin->identifier());
    expect(DB::table('team_members')->where('team_id', $team)->count())->toBe(0);
    $t->delete($team, $admin->identifier());
    expect(DB::table('teams')->where('id', $team)->whereNotNull('deleted_at')->count())->toBe(1)
        ->and(DB::table('queue_teams')->where('team_id', $team)->count())->toBe(0);
    expect(DB::table('audit_log')->whereIn('action_key', ['org.team.created', 'org.team.member_added', 'org.team.member_removed', 'org.team.deleted'])->count())->toBe(4);
});
