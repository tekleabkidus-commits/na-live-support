<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Structure\QueueWriter;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| PACKET P-DOORS LANDED (S12; countersigned at the batch-B acceptance) —
| the four system-door clauses, each with its control
|--------------------------------------------------------------------------
|
|  1. transferBySystem — actor system + rule_ref; a refusal fails THE ACTION
|     and the chain continues (the pre-landing fail-first was the pinned
|     `unbuilt_action` state, flipped here on the record).
|  2. add/removeBySystem tags — same posture.
|  4. escalateBySystem — the ONE door FR-03's arm and FR-08's hook share;
|     invalid named target refused honestly.
|  3. the pipeline's explicit_assignee_id hint (option a): the directed agent
|     is tried FIRST — never instead — and EVERY guard applies. The
|     countersign's ADDED control: RG FINALITY BEATS A DIRECTED ASSIGN — a
|     rule directing an agent onto an RG-flagged ticket cannot move it out of
|     the RG queue.
*/

function doorScene(): array
{
    [$tenant, , $ticket] = ticketFixture('open');
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    $admin = ticketActor(['org.queues.manage'], 'Door Admin');

    return [$tenant, $ticket, $admin];
}

it('clause 1: transfer_department moves the ticket with actor system + rule_ref; an empty destination fails THAT action and the chain continues', function (): void {
    [$tenant, $ticket] = doorScene();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $actions = [
        ['type' => 'transfer_department', 'department_id' => ''],
        ['type' => 'transfer_department', 'department_id' => $payments],
        ['type' => 'add_note', 'body' => 'after the refusal and the move'],
    ];
    $executionId = automationActionScene($tenant, $ticket, $actions);

    $outcomes = runChain($executionId, $actions);

    expect($outcomes[0]['reason'] ?? json_encode($outcomes))->toBe('transfer_refused')
        ->and($outcomes[1]['outcome'].'|'.($outcomes[1]['reason'] ?? '').'|'.($outcomes[1]['detail'] ?? ''))->toBe('ok||')
        ->and($outcomes[2]['outcome'])->toBe('ok');
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('department_id'))->toBe($payments);
    /** @var array{actor_type: string} $event */
    $event = json_decode((string) DB::table('outbox')->where('name', 'ticket.transferred')->orderByDesc('id')->value('payload'), true);
    expect($event['actor_type'])->toBe('system');
    /** @var array{rule_ref?: string} $audit */
    $audit = json_decode((string) DB::table('audit_log')->where('action_key', 'ticket.transferred')->orderByDesc('id')->value('payload'), true);
    expect($audit['rule_ref'] ?? null)->toBe('rule:'.$executionId);
});

it('clause 2: tags add/remove through the system arms with rule_ref; the tags land and lift', function (): void {
    [$tenant, $ticket] = doorScene();
    $actions = [
        ['type' => 'add_tags', 'tags' => ['deposit', 'telebirr']],
        ['type' => 'remove_tags', 'tags' => ['telebirr']],
        ['type' => 'add_tags', 'tags' => []],
    ];
    $executionId = automationActionScene($tenant, $ticket, $actions);

    $outcomes = runChain($executionId, $actions);

    expect($outcomes[0]['outcome'])->toBe('ok')
        ->and($outcomes[1]['outcome'])->toBe('ok')
        ->and($outcomes[2]['reason'])->toBe('no_tags');
    /** @var object{tags: string} $row */
    $row = DB::selectOne('SELECT array_to_json(tags)::text AS tags FROM tickets WHERE id = ?', [$ticket->identifier()]);
    expect(json_decode($row->tags, true))->toContain('deposit')
        ->and(json_decode($row->tags, true))->not->toContain('telebirr');
    /** @var array{rule_ref?: string} $audit */
    $audit = json_decode((string) DB::table('audit_log')->where('action_key', 'ticket.tagged')->orderByDesc('id')->value('payload'), true);
    expect($audit['rule_ref'] ?? null)->toBe('rule:'.$executionId);
});

it('clause 4: escalate lands through the ONE system door (actor system, rule-authored reason); an invalid named target fails honestly and the chain continues', function (): void {
    [$tenant, $ticket] = doorScene();
    $actions = [
        ['type' => 'escalate', 'target_id' => (string) Illuminate\Support\Str::uuid7(), 'reason' => 'bogus target, long enough reason'],
        ['type' => 'escalate', 'reason' => 'rule-authored escalation reason (FR-23)'],
    ];
    $executionId = automationActionScene($tenant, $ticket, $actions);

    $outcomes = runChain($executionId, $actions);

    expect($outcomes[0]['reason'])->toBe('escalation_refused')
        ->and($outcomes[1]['outcome'])->toBe('ok', json_encode($outcomes[1]));
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('status'))->toBe('escalated');
    /** @var array{actor_type: string} $event */
    $event = json_decode((string) DB::table('outbox')->where('name', 'ticket.escalated')->orderByDesc('id')->value('payload'), true);
    expect($event['actor_type'])->toBe('system');
});

it('clause 3 (option a): the DIRECTED agent is tried FIRST — with the strategy\'s own pick standing by, the hint wins; the record shows the directed step', function (): void {
    [$tenant, $ticket, $admin] = doorScene();
    // The pipeline resolves the DEPARTMENT first (tenant default = general, A-55);
    // an explicit queue must live IN it — so the scene stages the agents there
    // (cross-department movement is transfer_department's job, honestly).
    $general = (string) DB::table('departments')->where('slug', 'general')->value('id');
    $queue = (string) DB::table('departments')->where('id', $general)->value('default_queue_id');
    // Two routable agents; FirstEligible orders by user id — direct the LATER one,
    // so a strategy-only run would pick the other (the hint's proof, not an echo).
    $a = ticketActor(['tickets.view'], 'Directed A');
    $b = ticketActor(['tickets.view'], 'Directed B');
    foreach ([$a, $b] as $agent) {
        app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
        app(AgentStatus::class)->set($agent, 'online');
    }
    $directed = strcmp($a->identifier(), $b->identifier()) > 0 ? $a : $b;

    $actions = [['type' => 'assign', 'queue_id' => $queue, 'assignee_id' => $directed->identifier()]];
    $executionId = automationActionScene($tenant, $ticket, $actions);
    $outcomes = runChain($executionId, $actions, ['ticket.queue_id' => null]);

    expect($outcomes[0]['outcome'])->toBe('ok', json_encode($outcomes[0]));
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))
        ->toBe($directed->identifier(), 'the strategy outranked the directed hint — clause 3 is not first');
    /** @var object{record: string} $decision */
    $decision = DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->orderByDesc('at')->first(['record']);
    // (toContain is variadic — no message arg; the recorded trap's sixth sighting.)
    expect($decision->record)->toContain('"directed"');
});

it('clause 3 CONTROL (the countersign\'s addition): RG finality BEATS a directed assign — the ticket lands in the RG queue, never with the directed agent', function (): void {
    [$tenant, $ticket, $admin] = doorScene();
    $general = (string) DB::table('departments')->where('slug', 'general')->value('id');
    $queue = (string) DB::table('departments')->where('id', $general)->value('default_queue_id');
    // The tenant's SEEDED RG queue (OrganizationSeeder — at most one, FR-03);
    // the directed agent is a member of the NON-RG queue only, online and free.
    $rgQueue = (string) DB::table('queues')->where('is_rg', true)->value('id');
    expect($rgQueue)->not->toBe('');
    $agent = ticketActor(['tickets.view'], 'Directed RG Bypass Attempt');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
    app(AgentStatus::class)->set($agent, 'online');
    DB::table('customers')->where('id', DB::table('tickets')->where('id', $ticket->identifier())->value('customer_id'))
        ->update(['rg_flag' => true]);

    $actions = [['type' => 'assign', 'queue_id' => $queue, 'assignee_id' => $agent->identifier()]];
    $executionId = automationActionScene($tenant, $ticket, $actions);
    $outcomes = runChain($executionId, $actions, ['ticket.queue_id' => null]);

    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('queue_id'))
        ->toBe($rgQueue, 'a directed assign moved an RG-flagged ticket out of the RG queue — SEC-06/BR-02 broken');
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))
        ->not->toBe($agent->identifier(), 'the directed agent took an RG ticket from outside the RG queue');
    expect($outcomes[0]['routing_outcome'] ?? null)->not->toBeNull();
});
