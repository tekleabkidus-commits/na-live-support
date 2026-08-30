<?php

declare(strict_types=1);

use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 11 AC-01 / T-09 / FR-08 / BR-02 — THE DECISION ORDER, table-driven
|--------------------------------------------------------------------------
| fixtures/routing/fr08-order.json: every precedence (explicit > AI > connection-default >
| tenant-default; rule-tag > default queue; RG finality) proven from the RECORD's own steps.
| Starvation exemption and boost ordering are StarvationCapPropertyTest / DecisionRecordTest.
*/

/** @return array{depts: array<string, string>, queues: array<string, string>, admin: App\Models\User} */
function fr08Scene(): array
{
    $tenant = createTenant();
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();   // the FR-01 set incl. General + the is_rg queue
    $admin = ticketActor(['org.queues.manage'], 'Fixture FR08 Admin');
    $depts = [];
    foreach (DB::table('departments')->get(['id', 'slug', 'default_queue_id']) as $d) {
        $depts[(string) $d->slug] = (string) $d->id;
    }
    $queues = [
        'payments-default' => (string) DB::table('departments')->where('slug', 'payments')->value('default_queue_id'),
        'rg' => (string) DB::table('queues')->where('is_rg', true)->value('id'),
    ];
    $queues['payments-refunds'] = app(QueueWriter::class)->create($depts['payments'], 'Refunds', $admin->identifier());
    app(QueueWriter::class)->update($queues['payments-refunds'], ['routing_tags' => ['refund']], $admin->identifier());
    // The VIP department answers the `vip` connection tag by SLUG.
    unset($tenant);

    return ['depts' => $depts, 'queues' => $queues, 'admin' => $admin];
}

// Dataset load runs before the app boots (no base_path() yet) — resolve from this file.
$cases = json_decode((string) file_get_contents(__DIR__.'/../../../fixtures/routing/fr08-order.json'), true, 8, JSON_THROW_ON_ERROR)['cases'];

it('AC-01: FR-08\'s order holds for the fixture case', function (array $case) {
    $scene = fr08Scene();
    if (array_key_exists('autoaccept', $case)) {
        app(SettingsRepository::class)->set('routing.ai.autoaccept', (bool) $case['autoaccept']);
    }
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, tags: $case['ticket_tags'] ?? []));
    if (! empty($case['rg_flag'])) {
        DB::table('customers')->where('id', $ticket->customer_id)->update(['rg_flag' => true]);
    }
    if (isset($case['ai_suggested']) || ! empty($case['rg_reason'])) {
        $suggested = isset($case['ai_suggested']) ? $scene['depts'][$case['ai_suggested']] : null;
        DB::table('ticket_handoff_packets')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $ticket->getAttribute('tenant_id'), 'ticket_id' => $ticket->identifier(), 'schema_version' => 1,
            'payload' => json_encode(['intent' => ! empty($case['rg_reason']) ? 'rg_risk' : 'billing', 'suggested' => ['department' => $suggested, 'priority' => 'normal', 'reasons' => ! empty($case['rg_reason']) ? ['rg_risk', 'rg_queue'] : []]], JSON_THROW_ON_ERROR),
        ]);
    }
    $inputs = [
        'explicit_department_id' => isset($case['explicit_department']) ? $scene['depts'][$case['explicit_department']] : null,
        'connection_id' => isset($case['connection_tag']) ? 'fixture-conn-'.$case['connection_tag'] : null,
    ];

    $result = app(RoutingPipeline::class)->route($ticket, 'created', $inputs);
    $record = json_decode((string) DB::table('routing_decisions')->where('id', $result['record_id'])->value('record'), true, 16, JSON_THROW_ON_ERROR);
    $bySteps = [];
    foreach ($record['steps'] as $s) {
        $bySteps[$s['step']] = $s;
    }

    expect($result['department_id'])->toBe($scene['depts'][$case['expect_department']], $case['name']);
    if (isset($case['expect_step_source'])) {
        expect($bySteps['department']['input']['source'])->toBe($case['expect_step_source'], $case['name']);
    }
    if (! empty($case['expect_flagged'])) {
        expect($bySteps['department']['input']['ai_flagged'])->toBeTrue($case['name']);
    }
    if (isset($case['expect_queue'])) {
        expect($result['queue_id'])->toBe($scene['queues'][$case['expect_queue']], $case['name']);
    }
    if (isset($case['expect_queue_source'])) {
        expect($bySteps['queue']['input']['source'])->toBe($case['expect_queue_source'], $case['name']);
    }
    if (! empty($case['expect_rg_step'])) {
        expect(array_key_exists('rg_override', $bySteps))->toBeTrue($case['name'])
            ->and(DB::table('audit_log')->where('action_key', 'routing.rg_routed')->count())->toBe(1);
    }
    // Placement went through Doc 06's door: queue_id on the row, queued_at stamped by the trigger.
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('queue_id'))->toBe($result['queue_id'])
        ->and(DB::table('tickets')->where('id', $ticket->identifier())->value('queued_at'))->not->toBeNull();
})->with(array_combine(array_column($cases, 'name'), array_map(fn ($c) => [$c], $cases)));
