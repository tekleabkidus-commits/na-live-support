<?php

declare(strict_types=1);

use App\Domain\Automation\Engine\Evaluator;
use App\Domain\Automation\ExecutionLog\LogReader;
use App\Jobs\Automation\ActionChainJob;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-06 / SEC-03 / US-06 — the execution log's read side, role-fixtured
|--------------------------------------------------------------------------
|
| SEC-03: condition traces may embed MESSAGE CONTENT (the contains/keyword
| actuals). A viewer WITHOUT automation.rules.view reads matched-keyword
| MARKERS, never prose; a privileged viewer reads the evaluated values.
| US-06/FR-06: forTicket lists every matched rule touch with its rule link —
| the timeline's source rows.
*/

it('SEC-03 role fixtures: message-fact prose elides to markers for the unprivileged; the privileged read values; forTicket links rules', function (): void {
    Queue::fake([ActionChainJob::class]);
    [$tenant, $ticket, $author, $writer] = engineScene();
    $made = activeRule($writer, $author, 'body-scan', conditions: ['all' => [
        ['fact' => 'ticket.status', 'op' => 'equals', 'value' => 'open'],
        ['fact' => 'message.body', 'op' => 'contains', 'value' => 'telebirr'],
    ]]);
    // The context has no message (ticket.created) — message.body evaluates empty/false;
    // seed a second matched-only rule so forTicket has a row.
    $touch = activeRule($writer, $author, 'touch', conditions: ['all' => []]);
    $eventId = publishTicketCreated($tenant, $ticket);
    app(Evaluator::class)->evaluate($eventId, 'ticket.created');

    $reader = app(LogReader::class);
    $privileged = grantPermissions(ticketActor([], 'Log Priv Base'), ['automation.rules.view'], 'Log Privileged Role');
    $unprivileged = ticketActor(['tickets.view'], 'Log Unprivileged Role');

    $full = $reader->forRule($made['rule_id'], $privileged);
    $elided = $reader->forRule($made['rule_id'], $unprivileged);
    $fullLeaf = collect($full['executions'][0]['conditions'])->firstWhere('fact', 'message.body');
    $elidedLeaf = collect($elided['executions'][0]['conditions'])->firstWhere('fact', 'message.body');
    expect($fullLeaf['value'])->toBe('telebirr')
        ->and($elidedLeaf['value'])->toBe('[keyword]', 'the keyword pattern leaked to an unprivileged viewer (SEC-03)')
        ->and($elidedLeaf['actual'])->toBe('[no match]')
        ->and($elidedLeaf['matched'])->toBeFalse()
        // Non-message facts stay readable for both (absence of prose, not of record).
        ->and(collect($elided['executions'][0]['conditions'])->firstWhere('fact', 'ticket.status')['actual'])->toBe('open');

    $touches = $reader->forTicket($ticket->identifier());
    expect(array_column($touches, 'rule_id'))->toContain($touch['rule_id'])
        ->and($touches[0]['rule_name'])->not->toBe('');
    expect($full['matched_count'])->toBe(0)
        ->and($full['last_run_at'])->not->toBeNull();
});
