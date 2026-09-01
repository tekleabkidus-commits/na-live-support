<?php

declare(strict_types=1);

use App\Domain\Automation\DryRun\DryRunner;
use App\Domain\Automation\Engine\Evaluator;
use App\Jobs\Automation\ActionChainJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-05 / AC-02 / §9 / NFR-03 — DRY-RUN PARITY (simulation vs replay)
|--------------------------------------------------------------------------
|
| AC-02: dry-run verdicts EQUAL live evaluation over a replayed corpus — the
| Doc 09 AC-03 pattern. Parity is SHARED CODE (the same ConditionTree, the
| same context assembly), and the replay proves it against the recorded
| executions. ZERO SIDE EFFECTS are counted, not assumed: outbox rows,
| execution rows and queued chains before == after every dry-run. §9's
| per-condition badges are the returned trace. The floor and guard notes
| surface in the would-run list (the never-dry-run activation nudge's data).
*/

it('AC-02: replayed corpus verdicts are EQUAL, and the dry-run writes NOTHING', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author, $writer] = engineScene();
    activeRule($writer, $author, 'match-open', conditions: ['all' => [['fact' => 'ticket.status', 'op' => 'equals', 'value' => 'open']]]);
    activeRule($writer, $author, 'match-nothing', conditions: ['all' => [['fact' => 'ticket.priority', 'op' => 'equals', 'value' => 'urgent']]]);

    // The LIVE corpus: three events on the same trigger.
    $events = [];
    for ($i = 0; $i < 3; $i++) {
        $events[] = publishTicketCreated($tenant, $ticket);
    }
    $evaluator = app(Evaluator::class);
    foreach ($events as $i => $eventId) {
        if ($i > 0) {
            // cooldown 0 on both rules — every event evaluates fresh
        }
        $evaluator->evaluate($eventId, 'ticket.created');
    }

    $runner = app(DryRunner::class);
    $pushedBefore = 0;
    Queue::assertPushed(ActionChainJob::class, function () use (&$pushedBefore): bool {
        $pushedBefore++;

        return true;
    });
    $before = [
        'outbox' => (int) DB::table('outbox')->count(),
        'executions' => (int) DB::table('automation_rule_executions')->count(),
    ];
    $unequal = 0;
    $rows = 0;
    foreach ($events as $eventId) {
        foreach ($runner->replay($eventId) as $comparison) {
            $rows++;
            if (! $comparison['equal']) {
                $unequal++;
            }
        }
    }
    expect($rows)->toBeGreaterThanOrEqual(6, 'the replay saw no recorded corpus')
        ->and($unequal)->toBe(0, 'dry-run and live evaluation DISAGREED (AC-02)');
    expect((int) DB::table('outbox')->count())->toBe($before['outbox'], 'a dry-run published an event')
        ->and((int) DB::table('automation_rule_executions')->count())->toBe($before['executions'], 'a dry-run wrote an execution row');
    // The LIVE phase's own chains stand; the dry-run added ZERO.
    $pushedAfter = 0;
    Queue::assertPushed(ActionChainJob::class, function () use (&$pushedAfter): bool {
        $pushedAfter++;

        return true;
    });
    expect($pushedAfter)->toBe($pushedBefore, 'a dry-run dispatched a chain');
});

it('FR-05/§9: the drawer\'s data — per-condition badges with evaluated values, would-run actions with floor and guard notes, the 7-day picker', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author, $writer] = engineScene();
    $eventId = publishTicketCreated($tenant, $ticket);
    $runner = app(DryRunner::class);

    $facts = $runner->contextForEvent($eventId);
    expect($facts['ticket.status'])->toBe('open');

    $limited = ticketActor(['tickets.note'], 'DryRun Limited Role');
    $verdict = $runner->run([
        'conditions' => ['all' => [
            ['fact' => 'ticket.status', 'op' => 'equals', 'value' => 'open'],
            ['fact' => 'ticket.priority', 'op' => 'equals', 'value' => 'urgent'],
        ]],
        'actions' => [
            ['type' => 'add_note', 'body' => 'x'],
            ['type' => 'set_priority', 'priority' => 'high'],
            ['type' => 'force_field'],
        ],
    ], $facts, simulateActivator: $limited);

    expect($verdict['matched'])->toBeFalse()
        ->and($verdict['trace'])->toHaveCount(2)
        ->and($verdict['trace'][0]['matched'])->toBeTrue()
        ->and($verdict['trace'][0]['actual'])->toBe('open')
        ->and($verdict['trace'][1]['matched'])->toBeFalse();
    expect($verdict['actions'][0]['note'])->toBeNull()
        ->and($verdict['actions'][1]['note'])->toBe('floor_refused')
        ->and($verdict['actions'][2]['note'])->toBe('unknown_action');

    $picker = $runner->recentEvents();
    expect(array_column(array_map(fn (object $r): array => (array) $r, $picker), 'event_id'))->toContain($eventId);
});
