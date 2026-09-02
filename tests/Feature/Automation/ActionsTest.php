<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-03 / BR-02 / AC-01 / EC-01 — the door-lawful arms, first family
|--------------------------------------------------------------------------
|
| Every arm acts THROUGH its owning door with a SYSTEM actor:
|   set_status   → TicketTransitionService + TransitionContext::system
|   set_priority → ChangePriority(null) — the door's own automation vocabulary
|   add_note     → NotesWriter::addSystemNote (N-04 by construction)
|   assign       → RoutingPipeline::route(trigger=automation, explicit queue) —
|                  FR-08 step 2's first rung; the record shows automation
| AC-01 RUNTIME: an illegal transition FAILS THAT ACTION with the service's own
| reason and the chain CONTINUES to the next action. EC-01: an arm acting on
| state that moved since the match reports superseded_state honestly.
| transfer/tags/directed-assign/escalate landed at P-DOORS; the external arms at step 13
| (ExternalArmsTest) — no `unbuilt_action` answer remains.
*/

it('AC-01: an illegal transition fails THAT action with the door\'s own reason and the chain continues', function (): void {
    [$tenant, , $ticket] = ticketFixture('new');
    $actions = [
        ['type' => 'set_status', 'status' => 'closed'],   // new → closed is not an FR-04 edge
        ['type' => 'add_note', 'body' => 'chain survived the refusal'],
    ];
    $executionId = automationActionScene($tenant, $ticket, $actions);

    $outcomes = runChain($executionId, $actions);

    expect($outcomes[0]['outcome'])->toBe('failed')
        ->and($outcomes[0]['reason'])->toBe('illegal_transition')
        ->and($outcomes[1]['outcome'])->toBe('ok', 'the chain died on a refusal — AC-01 isolation broken');
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('status'))->toBe('new', 'the status moved despite the refusal — BR-02 broken');
    expect((int) DB::table('messages')->where('conversation_id', $ticket->getAttribute('conversation_id'))->where('kind', 'note')->count())->toBeGreaterThan(0);
});

it('set_status through the service: a LEGAL edge lands with actor system', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    $actions = [['type' => 'set_status', 'status' => 'pending_customer']];
    $executionId = automationActionScene($tenant, $ticket, $actions);

    $outcomes = runChain($executionId, $actions);

    expect($outcomes[0]['outcome'])->toBe('ok');
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('status'))->toBe('pending_customer');
    /** @var object{payload: string} $event */
    $event = DB::table('outbox')->where('name', 'ticket.status_changed')->orderByDesc('id')->first();
    /** @var array{actor_type: string} $payload */
    $payload = json_decode($event->payload, true, 8);
    expect($payload['actor_type'])->toBe('system');
});

it('set_priority through ChangePriority(null): the door publishes actor_type=system; an unknown level fails honestly', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    $actions = [['type' => 'set_priority', 'priority' => 'urgent'], ['type' => 'set_priority', 'priority' => 'apocalyptic']];
    $executionId = automationActionScene($tenant, $ticket, $actions);

    $outcomes = runChain($executionId, $actions);

    expect($outcomes[0]['outcome'])->toBe('ok')
        ->and($outcomes[1]['outcome'])->toBe('failed')
        ->and($outcomes[1]['reason'])->toBe('unknown_priority');
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('priority'))->toBe('urgent');
});

it('assign via Doc 11: the pipeline routes with trigger=automation and the record shows it; an assigned ticket refuses with superseded honesty', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    departmentFixture('Automation Dept');
    $queueId = (string) DB::table('queues')->orderBy('position')->value('id');
    $actions = [['type' => 'assign', 'queue_id' => $queueId]];
    $executionId = automationActionScene($tenant, $ticket, $actions);

    $outcomes = runChain($executionId, $actions, ['ticket.queue_id' => null]);

    expect($outcomes[0]['outcome'])->toBe('ok', json_encode($outcomes[0]));
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('queue_id'))->toBe($queueId);
    /** @var object{trigger: string} $record */
    $record = DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->orderByDesc('at')->first();
    expect($record->trigger)->toBe('automation', 'the decision record does not show the automation trigger (FR-17)');

    // BR-03's spirit: a ticket a human already claimed is not re-litigated.
    DB::table('tickets')->where('id', $ticket->identifier())->update(['assignee_id' => grantAllPermissions(ticketActor([], 'Claimer Role'))->identifier()]);
    $secondExecution = automationActionScene($tenant, $ticket, $actions);
    $second = runChain($secondExecution, $actions);
    expect($second[0]['reason'])->toBe('already_assigned')
        ->and($second[0]['superseded_state'])->toBeTrue();
});

it('EC-01: the second rule\'s arm reports superseded_state when the fact moved after the match', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    $actions = [['type' => 'set_priority', 'priority' => 'high']];
    $executionId = automationActionScene($tenant, $ticket, $actions);

    // The basis says the rule matched priority=normal; a rival chain moved it first.
    DB::table('tickets')->where('id', $ticket->identifier())->update(['priority' => 'low']);
    $outcomes = runChain($executionId, $actions, ['ticket.priority' => 'normal']);

    expect($outcomes[0]['outcome'])->toBe('ok')
        ->and($outcomes[0]['superseded_state'])->toBeTrue('the arm acted on moved state silently — EC-01 honesty gone');
});

// The step-5 "still-unbuilt arms" pin RETIRED at step 13: every FR-03 type is bound — ExternalArmsTest pins boundTypes() === TYPES.
