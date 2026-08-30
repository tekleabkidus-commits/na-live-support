<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Routing\Record\RecordReader;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-17 / BR-06 / AC-08 / SEC-02 / FS-04 / T-02 — THE RECORD, with the first engine line
|--------------------------------------------------------------------------
*/

function routingScene(): array
{
    createTenant();
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    $admin = ticketActor(['org.queues.manage'], 'Fixture Record Admin');
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');

    return [$admin, $payments, $queue];
}

it('FR-17/BR-06: every automatic routing writes a COMPLETE record — trigger, steps[{step,input,outcome}], strategy, eligible_count, boosts_applied, assigned_to|waiting — INSERT-only, with ticket.routed {record_id}; an assigned outcome went through Doc 06\'s system door', function () {
    [$admin, $payments, $queue] = routingScene();
    $agent = ticketActor(['tickets.view'], 'Fixture Routed Agent');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
    app(AgentStatus::class)->set($agent, 'online');
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, priority: 'urgent'));

    $result = app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $payments]);

    expect($result['outcome'])->toBe('assigned')->and($result['assigned_to'])->toBe($agent->identifier());
    $row = DB::table('routing_decisions')->where('id', $result['record_id'])->first();
    $record = json_decode((string) $row->record, true, 16, JSON_THROW_ON_ERROR);
    expect($record)->toHaveKeys(['trigger', 'steps', 'strategy', 'eligible_count', 'boosts_applied', 'assigned_to', 'waiting', 'outcome'])
        ->and($record['trigger'])->toBe('created')
        ->and(array_column($record['steps'], 'step'))->toBe(['department', 'queue', 'position', 'eligibility', 'assign'])
        ->and($record['boosts_applied'])->toBe([])   // the default queue has no boost list configured
        ->and($record['eligible_count'])->toBe(1)
        ->and($record['assigned_to'])->toBe($agent->identifier());
    foreach ($record['steps'] as $s) {
        expect($s)->toHaveKeys(['step', 'input', 'outcome'])->and($s['outcome'])->toBeString()->not->toBe('');
    }
    // The urgent priority MATCHED even though the queue applies no boosts (position input shows it).
    $position = collect($record['steps'])->firstWhere('step', 'position');
    expect($position['input']['matched'])->toContain('urgent_priority');
    // ticket.routed on the bus with the record id; the assignment audited as the SYSTEM actor with the decision id.
    $routed = json_decode((string) DB::table('outbox')->where('name', 'ticket.routed')->orderByDesc('id')->value('payload'), true);
    expect($routed['record_id'])->toBe($result['record_id']);
    $audit = json_decode((string) DB::table('audit_log')->where('action_key', 'ticket.assigned')->orderByDesc('id')->value('payload'), true);
    expect($audit['routing_decision_id'])->toBe($result['record_id']);
    // INSERT-only: app cannot rewrite the record (grant physics executed at 2b; re-asserted here behaviourally).
    expect(fn () => DB::table('routing_decisions')->where('id', $result['record_id'])->update(['outcome' => 'manual']))->toThrow(Illuminate\Database\QueryException::class);
});

it('waits honestly (BR-05): no online agent → outcome waiting, the ticket in its queue with ticket.waiting published, the record saying why', function () {
    [$admin, $payments, $queue] = routingScene();
    $away = ticketActor(['tickets.view'], 'Fixture Away Agent');
    app(QueueWriter::class)->addMember($queue, $away->identifier(), $admin->identifier());
    app(AgentStatus::class)->set($away, 'away');
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));

    $result = app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $payments]);
    expect($result['outcome'])->toBe('waiting')->and($result['queue_id'])->toBe($queue);
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBeNull();
    expect(DB::table('outbox')->where('name', 'ticket.waiting')->count())->toBe(1);
    $record = json_decode((string) DB::table('routing_decisions')->where('id', $result['record_id'])->value('record'), true);
    expect(collect($record['steps'])->firstWhere('step', 'eligibility')['outcome'])->toContain('no eligible agent');
});

it('FS-04: an engine exception PARKS the ticket in the department default with an error record + audit — never lost, never looping, queryable', function () {
    [$admin, $payments, $queue] = routingScene();
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));
    // Break the engine: a strategy that throws.
    app()->instance(App\Domain\Routing\Strategies\AssignmentStrategy::class, new class implements App\Domain\Routing\Strategies\AssignmentStrategy
    {
        public function name(): string
        {
            return 'broken';
        }

        public function order(string $queueId, array $routable, array $ticket): array
        {
            throw new LogicException('bad config edge (fixture)');
        }
    });

    $result = app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $payments]);
    expect($result['outcome'])->toBe('parked_error');
    expect(DB::table('routing_decisions')->where('ticket_id', $ticket->identifier())->where('outcome', 'parked_error')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'routing.engine_error_parked')->count())->toBe(1)
        ->and(DB::table('tickets')->where('id', $ticket->identifier())->value('queue_id'))->not->toBeNull('the parked ticket is not in a queue — it is LOST');
});

it('SEC-02: the read door ELIDES per-agent facts for a viewer without reports.agents.individual — absence, not redaction — and shows them to a supervision viewer; a ticket outside the viewer\'s scope is 404-shaped (null)', function () {
    [$admin, $payments, $queue] = routingScene();
    $agent = ticketActor(['tickets.view'], 'Fixture Routed Agent');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
    app(AgentStatus::class)->set($agent, 'online');
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));
    $result = app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $payments]);

    $plain = ticketActor(['tickets.view'], 'Fixture Plain Viewer');
    $supervisor = ticketActor(['tickets.view', 'reports.agents.individual'], 'Fixture Supervision Viewer');
    $reader = app(RecordReader::class);

    $elided = $reader->forViewer($plain, $ticket->identifier(), $result['record_id']);
    expect($elided)->not->toBeNull()
        ->and(array_key_exists('assigned_to', $elided))->toBeFalse('assigned_to is present (even as null) for a non-supervision viewer — that is redaction, not absence')
        ->and(json_encode($elided))->not->toContain($agent->identifier(), 'an agent id leaked into the elided record');
    foreach ($elided['steps'] as $s) {
        expect(array_keys($s['input']))->not->toContain('candidate')->not->toContain('routable')->not->toContain('candidates');
    }
    expect($elided['eligible_count'])->toBe(1);   // counts stay — the plain-language contract

    $full = $reader->forViewer($supervisor, $ticket->identifier(), $result['record_id']);
    expect($full['assigned_to'])->toBe($agent->identifier());
    expect(collect($full['steps'])->firstWhere('step', 'assign')['input']['candidate'])->toBe($agent->identifier());

    $blind = ticketActor([], 'Fixture Blind Viewer');
    expect($reader->forViewer($blind, $ticket->identifier(), $result['record_id']))->toBeNull();
});
