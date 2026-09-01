<?php

declare(strict_types=1);

use App\Domain\Automation\Rules\RuleWriter;
use App\Jobs\Automation\ActionChainJob;
use App\Jobs\Automation\AutomationEventConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-01 (ADR 0011) — the durable-consumer drill: kill / re-drive /
| exactly once
|--------------------------------------------------------------------------
|
| The registry entry, the doc12.json read-slice conformance and this drill
| land in the SAME commit (ADR 0011's law). The consumer carries ONLY the
| event id; a re-driven delivery finds the (rule, event) execution row already
| standing and changes NOTHING — C-09 as database physics, proven by running
| the consumer twice and by the read-slice check against the fixture.
*/

it('ADR 0011: the consumer evaluates once, and a re-driven delivery changes nothing', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author, $writer] = (function (): array {
        [$tenant, , $ticket] = ticketFixture('open');
        $author = grantAllPermissions(ticketActor([]));

        return [$tenant, $ticket, $author, app(RuleWriter::class)];
    })();
    $made = $writer->create(['name' => 'Drill', 'trigger' => 'ticket.created', 'conditions' => ['all' => []], 'actions' => [['type' => 'add_tags', 'tags' => ['drilled']]], 'cooldown_seconds' => 0], $author);
    $writer->activate($made['rule_id'], $made['revision_id'], $author);
    $eventId = publishTicketCreated($tenant, $ticket);

    (new AutomationEventConsumer($eventId))->handle(app(App\Domain\Automation\Engine\Evaluator::class));
    (new AutomationEventConsumer($eventId))->handle(app(App\Domain\Automation\Engine\Evaluator::class));

    expect((int) DB::table('automation_rule_executions')->where('event_id', $eventId)->count())->toBe(1, 'a re-driven delivery re-evaluated (C-09)');
    Queue::assertPushed(ActionChainJob::class, 1);
});

it('the doc12.json read-slice conformance: every payload key the consumer touches is a pinned read', function (): void {
    /** @var array{entries: list<array{event: string, reads: list<string>}>} $fixture */
    $fixture = json_decode((string) file_get_contents(base_path('fixtures/tickets/consumers/doc12.json')), true, 8, JSON_THROW_ON_ERROR);
    $pinned = [];
    foreach ($fixture['entries'] as $entry) {
        $pinned[$entry['event']] = $entry['reads'];
    }
    // EventContext reads exactly these payload keys for ticket events (the rest
    // comes from ROWS, the RollupConsumer posture): ticket_id + customer_id.
    foreach (['ticket.created', 'ticket.status_changed', 'ticket.priority_changed', 'ticket.transferred'] as $event) {
        // (Pest's toHaveKey second arg is a VALUE, not a message — the recorded trap.)
        expect(isset($pinned[$event]))->toBeTrue("the doc12 fixture lost its {$event} entry");
        foreach (['tenant_id', 'ticket_id'] as $key) {
            // toContain is VARIADIC needles (the S11-recorded trap) — no message arg.
            expect($pinned[$event])->toContain($key);
        }
    }
});
