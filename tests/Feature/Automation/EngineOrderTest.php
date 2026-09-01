<?php

declare(strict_types=1);

use App\Domain\Automation\Engine\Evaluator;
use App\Domain\Automation\Rules\RuleWriter;
use App\Jobs\Automation\ActionChainJob;
use App\Support\Cache\TenantCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-04 / BR-01 / EC-12 / T-02 / FS-03 / R-04 / AC-01's order half
|--------------------------------------------------------------------------
|
| - BR-01: candidates run in POSITION order — what the admin sees is the
|   execution order; the dispatched chains observe it.
| - Trigger index: a rule wakes ONLY for its trigger.
| - C-09: re-evaluating the same event inserts nothing and dispatches nothing
|   (the (rule, event) unique — the consumer drill's other half).
| - EC-12: cooldown keys on (rule, ticket) — the SAME ticket suppresses inside
|   the window, a SECOND ticket evaluates fresh (fixture-pinned).
| - FS-03: a corrupt compiled-cache entry falls back to interpretive
|   evaluation — slower, correct, healing.
| - R-04: the rules-pause switch short-circuits evaluation instantly; armed
|   state and logs untouched (RulesPauseTest's deeper legs ride step 7; the
|   switch's evaluation effect is pinned here where the evaluator lives).
*/

function engineScene(): array
{
    [$tenant, , $ticket] = ticketFixture('open');
    $author = grantAllPermissions(ticketActor([]));

    return [$tenant, $ticket, $author, app(RuleWriter::class)];
}

function activeRule(RuleWriter $writer, object $author, string $name, string $trigger = 'ticket.created', int $cooldown = 0, array $conditions = ['all' => []]): array
{
    $made = $writer->create(['name' => $name, 'trigger' => $trigger, 'conditions' => $conditions, 'actions' => [['type' => 'add_tags', 'tags' => [$name]]], 'cooldown_seconds' => $cooldown], $author);
    $writer->activate($made['rule_id'], $made['revision_id'], $author);

    return $made;
}

it('BR-01: rules evaluate and dispatch in position order — the admin\'s list IS the execution order', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author, $writer] = engineScene();
    $first = activeRule($writer, $author, 'first');
    $second = activeRule($writer, $author, 'second');
    $eventId = publishTicketCreated($tenant, $ticket);

    app(Evaluator::class)->evaluate($eventId, 'ticket.created');

    $pushed = [];
    Queue::assertPushed(ActionChainJob::class, function (ActionChainJob $job) use (&$pushed): bool {
        $pushed[] = $job->executionId;

        return true;
    });
    $ruleOf = fn (string $executionId): string => (string) DB::table('automation_rule_executions')->where('id', $executionId)->value('rule_id');
    expect(array_map($ruleOf, $pushed))->toBe([$first['rule_id'], $second['rule_id']], 'chains dispatched out of position order (BR-01)');
});

it('the trigger index: a rule wakes only for its own trigger', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author, $writer] = engineScene();
    activeRule($writer, $author, 'created-only', 'ticket.created');
    activeRule($writer, $author, 'priority-only', 'ticket.priority_changed');
    $eventId = publishTicketCreated($tenant, $ticket);

    app(Evaluator::class)->evaluate($eventId, 'ticket.created');

    expect((int) DB::table('automation_rule_executions')->where('event_id', $eventId)->count())->toBe(1);
});

it('C-09: a redelivered event inserts nothing and dispatches nothing — the (rule, event) unique is the idempotency', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author, $writer] = engineScene();
    activeRule($writer, $author, 'once');
    $eventId = publishTicketCreated($tenant, $ticket);
    $evaluator = app(Evaluator::class);

    $evaluator->evaluate($eventId, 'ticket.created');
    $evaluator->evaluate($eventId, 'ticket.created');

    expect((int) DB::table('automation_rule_executions')->where('event_id', $eventId)->count())->toBe(1);
    Queue::assertPushed(ActionChainJob::class, 1);
});

it('EC-12: cooldown keys on (rule, ticket) — the same ticket suppresses, a second ticket evaluates fresh', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author, $writer] = engineScene();
    $rule = activeRule($writer, $author, 'cool', cooldown: 300);

    $firstEvent = publishTicketCreated($tenant, $ticket);
    app(Evaluator::class)->evaluate($firstEvent, 'ticket.created');
    expect((int) DB::table('automation_rule_executions')->where('rule_id', $rule['rule_id'])->where('matched', true)->count())->toBe(1);

    // The SAME ticket inside the window: suppressed (no new row at all — a
    // cooldown suppression is not an evaluation).
    $secondEvent = publishTicketCreated($tenant, $ticket);
    app(Evaluator::class)->evaluate($secondEvent, 'ticket.created');
    expect((int) DB::table('automation_rule_executions')->where('rule_id', $rule['rule_id'])->count())->toBe(1);

    // A DIFFERENT ticket: fresh (EC-12's linked-work shape — the second ticket
    // is a new key).
    $other = anotherConversation($tenant->identifier(), 'cooldown-second');
    $otherTicketId = (string) Illuminate\Support\Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $otherTicketId, 'tenant_id' => $tenant->identifier(),
        'conversation_id' => $other->identifier(), 'customer_id' => $other->customer_id,
        'number' => random_int(1, 2_000_000_000), 'status' => 'open', 'priority' => 'normal', 'source' => 'agent',
    ]);
    $thirdEvent = DB::transaction(fn (): string => app(App\Support\Events\EventPublisher::class)->publish('ticket.created', [
        'tenant_id' => $tenant->identifier(), 'ticket_id' => $otherTicketId,
        'conversation_id' => $other->identifier(), 'actor_type' => 'system', 'actor_id' => null,
        'source' => 'agent', 'customer_id' => (string) $other->customer_id,
    ]));
    app(Evaluator::class)->evaluate($thirdEvent, 'ticket.created');
    expect((int) DB::table('automation_rule_executions')->where('rule_id', $rule['rule_id'])->count())->toBe(2, 'the second ticket was cooldown-suppressed — EC-12 keys on rule alone');
});

it('FS-03: a corrupt compiled cache falls back to interpretive evaluation — correct, healing', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author, $writer] = engineScene();
    activeRule($writer, $author, 'survives-corruption');
    app(TenantCache::class)->put('automation:compiled:ticket.created', ['garbage' => 'not-a-rule-list'], 600, 'test-corruption');
    $eventId = publishTicketCreated($tenant, $ticket);

    app(Evaluator::class)->evaluate($eventId, 'ticket.created');

    expect((int) DB::table('automation_rule_executions')->where('event_id', $eventId)->where('matched', true)->count())->toBe(1, 'the corrupt cache killed evaluation instead of falling back (FS-03)');
});

it('R-04: the rules-pause switch stops evaluation instantly and destroys nothing', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author, $writer] = engineScene();
    $rule = activeRule($writer, $author, 'pausable');
    Feature::activate('automation.rules_pause');
    try {
        $eventId = publishTicketCreated($tenant, $ticket);
        app(Evaluator::class)->evaluate($eventId, 'ticket.created');
        expect((int) DB::table('automation_rule_executions')->where('event_id', $eventId)->count())->toBe(0);
        Queue::assertNothingPushed();
        // N-19: the switch hides behavior; the rule row is untouched.
        expect((bool) DB::table('automation_rules')->where('id', $rule['rule_id'])->value('active'))->toBeTrue();
    } finally {
        Feature::deactivate('automation.rules_pause');
    }
});
