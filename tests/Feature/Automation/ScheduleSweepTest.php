<?php

declare(strict_types=1);

use App\Domain\Automation\Engine\ScheduleSweep;
use App\Domain\Automation\Rules\RuleWriter;
use App\Jobs\Automation\ActionChainJob;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-01's schedule trigger / EC-10 — the sweep: arm, fire, re-arm
|--------------------------------------------------------------------------
|
| - A fresh (or EC-10-cleared) schedule rule ARMS without firing — activation
|   is never itself a firing.
| - A due rule fires ONCE, writes its execution row, dispatches its chain, and
|   re-arms in the tenant's timezone (late-not-early: one firing per recovery).
| - R-04's pause silences scheduled rules exactly like evented ones (re-armed,
|   not fired).
| The sweep is the house cross-tenant shape (platform discovery, per-tenant
| processing) — the test clears the tenant binding the way relay tests do.
*/

function scheduleScene(string $cron = '0 2 * * *'): array
{
    $tenant = createTenant();
    bindTenant($tenant);
    $author = grantAllPermissions(ticketActor([]));
    $writer = app(RuleWriter::class);
    $made = $writer->create(['name' => 'Nightly', 'trigger' => 'schedule', 'schedule_cron' => $cron, 'conditions' => ['all' => []], 'actions' => [['type' => 'add_note', 'body' => 'tick']], 'cooldown_seconds' => 0], $author);
    $writer->activate($made['rule_id'], $made['revision_id'], $author);
    commitFixtures();

    return [$tenant, $made['rule_id']];
}

function sweepUnbound(): array
{
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return app(ScheduleSweep::class)->sweep();
}

it('arms without firing, then fires ONCE when due and re-arms in the future', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ruleId] = scheduleScene();

    // Pass 1: the unarmed rule gets its next instant — no firing.
    $first = sweepUnbound();
    bindTenant($tenant);
    $armed = DB::table('automation_rules')->where('id', $ruleId)->value('schedule_next_run_at');
    expect($armed)->not->toBeNull('the sweep did not arm the fresh rule')
        ->and((int) DB::table('automation_rule_executions')->where('rule_id', $ruleId)->count())->toBe(0, 'arming fired the rule');
    Queue::assertNothingPushed();

    // Force due (the app lawfully writes this column), then pass 2 fires once.
    DB::table('automation_rules')->where('id', $ruleId)->update(['schedule_next_run_at' => DB::raw("clock_timestamp() - interval '1 minute'")]);
    commitFixtures();
    sweepUnbound();
    bindTenant($tenant);
    expect((int) DB::table('automation_rule_executions')->where('rule_id', $ruleId)->where('matched', true)->count())->toBe(1);
    Queue::assertPushed(ActionChainJob::class, 1);
    /** @var object{schedule_next_run_at: string} $after */
    $after = DB::table('automation_rules')->where('id', $ruleId)->first(['schedule_next_run_at']);
    expect(new DateTimeImmutable($after->schedule_next_run_at) > new DateTimeImmutable('now'))
        ->toBeTrue('the fired rule did not re-arm in the future (late-not-early)');
});

it('R-04: a paused tenant\'s due schedule rule re-arms silently — nothing fires, nothing is destroyed', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ruleId] = scheduleScene();
    bindTenant($tenant);
    DB::table('automation_rules')->where('id', $ruleId)->update(['schedule_next_run_at' => DB::raw("clock_timestamp() - interval '1 minute'")]);
    Feature::activate('automation.rules_pause');
    commitFixtures();
    try {
        sweepUnbound();
        bindTenant($tenant);
        expect((int) DB::table('automation_rule_executions')->where('rule_id', $ruleId)->count())->toBe(0);
        Queue::assertNothingPushed();
        expect(DB::table('automation_rules')->where('id', $ruleId)->value('schedule_next_run_at'))->not->toBeNull();
    } finally {
        Feature::deactivate('automation.rules_pause');
    }
});
