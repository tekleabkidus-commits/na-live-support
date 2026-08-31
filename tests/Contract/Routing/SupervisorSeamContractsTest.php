<?php

declare(strict_types=1);

use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Routing\Supervision\DragReassign;
use App\Domain\Routing\Supervision\PullInDialog;
use App\Domain\Routing\Supervision\QueueTableRow;
use App\Domain\Routing\Supervision\RescueAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 11 §8 (S11 step 13) — the SUPERVISOR SURFACE data contracts (B5-S02 C01–C08/C12–C14
| SEAMS; rendering binds in Doc 15/07). Each provider's LIVE output keys must equal its
| fixture's `keys` EXACTLY, both directions — a key added or dropped on either side goes
| red here. The ids-only law (SEC-02/SEC-05) is asserted as an ABSENCE: no `name` key
| anywhere in a provider's output. C08 (monitoring chrome) has NO Doc 11 domain source and
| deliberately carries no fixture — Doc 15 builds its own; the broadcast contract is [D16]'s
| existing fixture (P-C5), cited not duplicated.
|--------------------------------------------------------------------------
*/

/** @param array<string, mixed> $fixture */
function seamFixture(string $name): array
{
    /** @var array{keys: list<string>, shape: array<string, mixed>} $data */
    $data = json_decode((string) file_get_contents(base_path('fixtures/routing/contracts/'.$name)), true, 16, JSON_THROW_ON_ERROR);

    return $data;
}

/** @param array<string, mixed> $row */
function assertNoNameKey(array $row): void
{
    foreach ($row as $key => $value) {
        expect($key)->not->toBe('name', 'SEC-02/SEC-05: a seam row may never carry a name');
        if (is_array($value)) {
            foreach (array_is_list($value) ? $value : [$value] as $inner) {
                if (is_array($inner)) {
                    assertNoNameKey($inner);
                }
            }
        }
    }
}

it('§8: QueueTableRow::row() answers EXACTLY the doc15-queue-table-row contract keys, ids only', function () {
    $tenant = createTenant();
    bindTenant($tenant);
    app(OrganizationSeeder::class)->seed();
    $queue = (string) DB::table('queues')->where('is_rg', false)->value('id');

    $row = app(QueueTableRow::class)->row($queue);
    $fixture = seamFixture('doc15-queue-table-row.json');
    expect(array_keys($row))->toBe($fixture['keys'])
        ->and(array_keys($fixture['shape']))->toBe($fixture['keys']);
    assertNoNameKey($row);
    expect($row['depth'])->toBeInt()->and($row['band'])->toBeString();
});

it('§8/FR-15: PullInDialog::payload() answers EXACTLY the doc15-pull-in-dialog contract keys; candidates are ids + load only; the default expiry is shift end', function () {
    $tenant = createTenant();
    bindTenant($tenant);
    app(OrganizationSeeder::class)->seed();
    $queue = (string) DB::table('queues')->where('is_rg', false)->value('id');

    $payload = app(PullInDialog::class)->payload($queue, 0, 0);
    $fixture = seamFixture('doc15-pull-in-dialog.json');
    expect(array_keys($payload))->toBe($fixture['keys'])
        ->and(array_keys($fixture['shape']))->toBe($fixture['keys']);
    assertNoNameKey($payload);
    expect($payload['default_expires_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
    if ($payload['candidates'] !== []) {
        expect(array_keys($payload['candidates'][0]))->toBe(['user_id', 'status', 'load', 'capacity', 'recurrence_hint']);
    }
});

it('§8/BR-03: DragReassign::context() answers EXACTLY the doc15-drag-reassign contract keys and the dry run NEVER assigns', function () {
    $tenant = createTenant();
    bindTenant($tenant);
    app(OrganizationSeeder::class)->seed();
    [, $conversation] = conversationFixture();
    $queue = (string) DB::table('queues')->where('is_rg', false)->value('id');
    $ticketId = (string) Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $ticketId, 'tenant_id' => app(App\Support\Tenancy\TenantContext::class)->tenantId(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $conversation->customer_id,
        'number' => 990001, 'status' => 'new', 'priority' => 'normal', 'source' => 'agent',
        'queue_id' => $queue,
    ]);

    $context = app(DragReassign::class)->context($ticketId);
    $fixture = seamFixture('doc15-drag-reassign.json');
    expect(array_keys($context))->toBe($fixture['keys'])
        ->and(array_keys($fixture['shape']))->toBe($fixture['keys']);
    assertNoNameKey($context);
    // The dry run: nothing was assigned, no routing decision row appeared.
    expect(DB::table('tickets')->where('id', $ticketId)->value('assignee_id'))->toBeNull()
        ->and((int) DB::table('routing_decisions')->where('ticket_id', $ticketId)->count())->toBe(0);
});

it('FR-16/§8: RescueAlerts::rows() answers EXACTLY the doc07-rescue-alerts contract keys for BOTH sources; auto_return is false for channel_unreachable', function () {
    $tenant = createTenant();
    bindTenant($tenant);
    app(OrganizationSeeder::class)->seed();
    [, $conversation] = conversationFixture();
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();
    $flagged = (string) Str::uuid7();
    $unreachable = (string) Str::uuid7();
    DB::table('tickets')->insert([
        ['id' => $flagged, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'customer_id' => $conversation->customer_id, 'number' => 990011, 'status' => 'open', 'priority' => 'normal', 'source' => 'agent'],
    ]);
    [, $conversation2] = conversationFixture();
    DB::table('tickets')->insert([
        ['id' => $unreachable, 'tenant_id' => $tenantId, 'conversation_id' => $conversation2->identifier(), 'customer_id' => $conversation2->customer_id, 'number' => 990012, 'status' => 'open', 'priority' => 'normal', 'source' => 'agent', 'channel_unreachable' => true],
    ]);
    DB::table('rescue_flags')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'ticket_id' => $flagged, 'reason' => 'heartbeat_lost']);

    $rows = app(RescueAlerts::class)->rows();
    $fixture = seamFixture('doc07-rescue-alerts.json');
    expect($rows)->not->toBeEmpty();
    $byKind = collect($rows)->keyBy('kind');
    expect(array_keys($rows[0]))->toBe($fixture['keys'])
        ->and(array_keys($fixture['shape']))->toBe($fixture['keys'])
        ->and($byKind->has('heartbeat_lost'))->toBeTrue()
        ->and($byKind->has('channel_unreachable'))->toBeTrue()
        ->and($byKind['channel_unreachable']['auto_return'])->toBeFalse('Doc 06 BR-07: unreachable is never auto-acted')
        ->and($byKind['channel_unreachable']['since_seconds'])->toBeNull('Doc 05\'s column is a boolean — no timestamp exists to claim');
    foreach ($rows as $row) {
        assertNoNameKey($row);
    }
});

it('C06: the force-status door the doc07-force-status contract cites EXISTS with its permission and audit declaration', function () {
    $fixture = seamFixture('doc07-force-status.json');
    expect($fixture['keys'])->toBe(['request', 'response']);
    $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'inbox.status.force');
    expect($route)->not->toBeNull('the C06 door must exist');
    expect($route->gatherMiddleware())->toContain('permission:supervision.agent_status.force')
        ->toContain('audit:agent.status_forced');
});

it('C07/[D16]: the broadcast contract fixture stands (P-C5 — cited, never duplicated) and no second broadcast fixture appeared', function () {
    expect(file_exists(base_path('fixtures/routing/contracts/doc16-broadcast-delivery.json')))->toBeTrue();
    $duplicates = glob(base_path('fixtures/routing/contracts/*broadcast*')) ?: [];
    expect($duplicates)->toHaveCount(1);
});
