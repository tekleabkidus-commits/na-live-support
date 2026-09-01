<?php

declare(strict_types=1);

use App\Domain\Automation\Engine\Evaluator;
use App\Domain\Automation\Engine\Guards;
use App\Domain\Automation\Rules\RuleWriter;
use App\Jobs\Automation\ActionChainJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-04 / BR-05 / T-03 / FS-05 — THE GUARD CAPS ARE PLATFORM PHYSICS
|--------------------------------------------------------------------------
|
| Written BEFORE the engine executed anything (S12 plan step 4, the safety
| floor; the batch-B ruling's own order). Five properties:
|
|  P1  THE CAPS ARE NOT CONFIGURATION — no settings-registry key names them
|      (BR-05: a cap configurable above the platform limit is not a cap), and
|      the constants hold the document's numbers (10/20/3).
|  P2  COOLDOWN VALIDATION PHYSICS — the column CHECK refuses 86401 and -1
|      (operators tune WITHIN the platform cap, never above it; R-05: zero is
|      lawful).
|  P3  MAX-RULES BLAST BOUND — 12 matching rules on one event: exactly 10 fire,
|      the loop_guard trips max_rules (FS-05's per-event damage bound).
|  P4  MAX-ACTIONS BLAST BOUND — one rule with 25 actions: its chain carries
|      exactly 20, the loop_guard trips max_actions.
|  P5  CHAIN-DEPTH SKIP — an event arriving with envelope automation_depth = 3
|      evaluates NOTHING and trips chain_depth (T-03).
|
| Sabotage (a): a cap constant raised/removed or the guard folded away → P3/P4/
| P5 red.
*/

function guardScene(): array
{
    [$tenant, , $ticket] = ticketFixture('open');
    $author = grantAllPermissions(ticketActor([]));

    return [$tenant, $ticket, $author];
}

it('P1: the caps are code constants — no settings key names them, and they hold the document numbers', function (): void {
    expect(Guards::MAX_RULES_PER_EVENT)->toBe(10)
        ->and(Guards::MAX_ACTIONS_PER_EVENT)->toBe(20)
        ->and(Guards::MAX_CHAIN_DEPTH)->toBe(3);

    /** @var list<array{key: string}> $settings */
    $settings = require base_path('registries/settings.php');
    foreach ($settings as $entry) {
        expect((string) preg_match('/automation\.(max_rules|max_actions|chain_depth|guard)/', $entry['key']))
            ->toBe('0', "a settings key configures a guard cap: {$entry['key']} — BR-05 broken");
    }
});

it('P2: the cooldown column CHECK is the tuning boundary — 86401 and -1 both die, 0 is lawful (R-05)', function (): void {
    [$tenant] = guardScene();
    foreach ([86401, -1] as $bad) {
        try {
            DB::transaction(static fn () => DB::table('automation_rules')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(),
                'name' => 'Bad cooldown', 'trigger' => 'ticket.created', 'position' => 999,
                'cooldown_seconds' => $bad,
            ]));
            $this->fail("cooldown {$bad} landed — the platform cap is gone");
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('automation_rules_cooldown_check');
        }
    }
    DB::table('automation_rules')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(),
        'name' => 'Zero cooldown', 'trigger' => 'ticket.created', 'position' => 998,
        'cooldown_seconds' => 0,
    ]);
    expect(true)->toBeTrue();
});

it('P3: twelve matching rules, one event — exactly ten fire and the loop_guard trips max_rules', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author] = guardScene();
    $writer = app(RuleWriter::class);
    for ($i = 1; $i <= 12; $i++) {
        $made = $writer->create(['name' => "Blast {$i}", 'trigger' => 'ticket.created', 'conditions' => ['all' => []], 'actions' => [['type' => 'add_tags', 'tags' => ['t'.$i]]], 'cooldown_seconds' => 0], $author);
        $writer->activate($made['rule_id'], $made['revision_id'], $author);
    }
    $eventId = publishTicketCreated($tenant, $ticket);

    app(Evaluator::class)->evaluate($eventId, 'ticket.created');

    expect((int) DB::table('automation_rule_executions')->where('event_id', $eventId)->where('matched', true)->count())
        ->toBe(Guards::MAX_RULES_PER_EVENT, 'the max-rules cap did not bound the blast');
    Queue::assertPushed(ActionChainJob::class, Guards::MAX_RULES_PER_EVENT);
    expect((int) DB::table('outbox')->where('name', 'automation.loop_guard.tripped')->count())->toBeGreaterThan(0);
});

it('P4: one rule with twenty-five actions — its chain carries exactly twenty and max_actions trips', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author] = guardScene();
    $writer = app(RuleWriter::class);
    $actions = [];
    for ($i = 0; $i < 25; $i++) {
        $actions[] = ['type' => 'add_tags', 'tags' => ['t'.$i]];
    }
    $made = $writer->create(['name' => 'Wide', 'trigger' => 'ticket.created', 'conditions' => ['all' => []], 'actions' => $actions, 'cooldown_seconds' => 0], $author);
    $writer->activate($made['rule_id'], $made['revision_id'], $author);
    $eventId = publishTicketCreated($tenant, $ticket);

    app(Evaluator::class)->evaluate($eventId, 'ticket.created');

    Queue::assertPushed(ActionChainJob::class, function (ActionChainJob $job): bool {
        return count($job->actions) === Guards::MAX_ACTIONS_PER_EVENT;
    });
    /** @var object{guard_trips: string} $execution */
    $execution = DB::table('automation_rule_executions')->where('event_id', $eventId)->first();
    expect($execution->guard_trips)->toContain('max_actions');
});

it('P5: an event carrying automation_depth 3 evaluates NOTHING and trips chain_depth (T-03)', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author] = guardScene();
    $writer = app(RuleWriter::class);
    $made = $writer->create(['name' => 'Depth victim', 'trigger' => 'ticket.created', 'conditions' => ['all' => []], 'actions' => [['type' => 'add_tags', 'tags' => ['x']]], 'cooldown_seconds' => 0], $author);
    $writer->activate($made['rule_id'], $made['revision_id'], $author);
    $eventId = publishTicketCreated($tenant, $ticket, ['automation_depth' => Guards::MAX_CHAIN_DEPTH]);

    app(Evaluator::class)->evaluate($eventId, 'ticket.created');

    expect((int) DB::table('automation_rule_executions')->where('event_id', $eventId)->count())->toBe(0, 'a depth-3 event evaluated — the loop guard is gone');
    Queue::assertNothingPushed();
    /** @var object{payload: string}|null $trip */
    $trip = DB::table('outbox')->where('name', 'automation.loop_guard.tripped')->orderByDesc('id')->first();
    expect($trip)->not->toBeNull()
        ->and($trip->payload)->toContain('chain_depth');
});
