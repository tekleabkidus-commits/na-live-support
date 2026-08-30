<?php

declare(strict_types=1);

use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Strategies\SkillBased;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Doc 11 FR-11 / T-05 / EC-04 / BR-01 / AC-02 — skill matcher + BOTH fallback modes + the cap interaction.
*/
function skillScene(string $fallback): array
{
    $tenant = createTenant();
    $department = departmentFixture('Payments');
    $queue = (string) DB::table('departments')->where('id', $department)->value('default_queue_id');
    $admin = ticketActor(['org.queues.manage'], 'Fixture Skill Admin');
    app(QueueWriter::class)->update($queue, ['assignment_strategy' => 'skill_based', 'skill_matchers' => [['attribute' => 'language', 'requirement' => 'match'], ['attribute' => 'department', 'requirement' => 'proficient']], 'skill_fallback' => $fallback], $admin->identifier());
    $mk = function (string $label, array $languages, array $departments) use ($tenant): string {
        $u = ticketActor(['tickets.view'], "Fixture Skill {$label}");
        DB::table('user_skills')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(), 'user_id' => $u->identifier(), 'languages' => '{'.implode(',', $languages).'}', 'department_ids' => '{'.implode(',', $departments).'}']);

        return $u->identifier();
    };
    $am = $mk('amharic', ['am', 'en'], [$department]);
    $en = $mk('english', ['en'], [$department]);
    $routable = array_map(fn ($id) => ['user_id' => $id, 'capacity' => 5, 'load' => 0, 'free' => 5], [$am, $en]);
    sort($routable);

    return [$queue, $department, $am, $en, $routable];
}

it('filters to skill-matching agents then load-based among them (US-02: Amharic payments → the Amharic payments person)', function () {
    [$queue, $department, $am, $en, $routable] = skillScene('nearest_skill');
    $out = app(SkillBased::class)->order($queue, $routable, ['language' => 'am', 'department_id' => $department]);
    expect($out['candidates'])->toBe([$am]);
    $out = app(SkillBased::class)->order($queue, $routable, ['language' => 'en', 'department_id' => $department]);
    expect($out['candidates'])->toHaveCount(2);
    unset($en);
});

it('nearest_skill: no language match → the language requirement drops, department proficiency kept', function () {
    [$queue, $department, $am, $en, $routable] = skillScene('nearest_skill');
    $out = app(SkillBased::class)->order($queue, $routable, ['language' => 'fr', 'department_id' => $department]);
    expect($out['candidates'])->toHaveCount(2)->and($out['note'])->toContain('nearest_skill');
    unset($am, $en);
});

it('wait: no language match → HOLD (no candidates) with the online/skill breakdown in the note; EC-04: STARVED past the cap → the language requirement drops even in wait mode (BR-01 over matching)', function () {
    [$queue, $department, $am, $en, $routable] = skillScene('wait');
    $hold = app(SkillBased::class)->order($queue, $routable, ['language' => 'fr', 'department_id' => $department]);
    expect($hold['candidates'])->toBe([])->and($hold['note'])->toContain('HOLD');
    $starved = app(SkillBased::class)->order($queue, $routable, ['language' => 'fr', 'department_id' => $department, 'starved' => true]);
    expect($starved['candidates'])->toHaveCount(2)->and($starved['note'])->toContain('STARVED')->and($starved['note'])->toContain('DROPPED');
    unset($am, $en);
});
