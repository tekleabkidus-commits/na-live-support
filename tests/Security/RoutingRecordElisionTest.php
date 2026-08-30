<?php

declare(strict_types=1);

use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Routing\Record\RecordReader;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 11 SEC-02 / AC-08's elision half (S11 step 11b; the §8-named file) — "the record
| renders with agent names elided otherwise": elision is ABSENCE, not redaction. For a
| viewer WITHOUT reports.agents.individual the payload carries NO agent key at all — no
| assigned_to, no names map, no candidate/by/routable inputs; the record's LOGIC (steps,
| outcomes, strategy, boosts) stays whole (C11's own note). Sabotage #12 (record elision
| dropped) was executed against this family at step 5; this file closes AC-08's second
| half with the door-level proof (the VISIBLE-absence browser leg rides
| routing-record.spec.ts).
|--------------------------------------------------------------------------
*/

function elisionActor(array $permissions, string $roleName): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName], ['color' => 'role-agent', 'permissions' => $permissions, 'scopes' => ['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All']]);
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

it('SEC-02: the SAME record answers BOTH roles — the supervision viewer gets assigned_to + a names map (real display names, never raw-id-only) and full step inputs; the plain viewer gets NO agent key anywhere (absence, not redaction) while every step, outcome, strategy and boost stays whole', function () {
    $tenant = createTenant();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $admin = elisionActor(['org.queues.manage', 'tickets.view'], 'S11 Elision Admin');
    $agent = User::factory()->create(['name' => 'Fixture Elision Agent Dawit']);
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($agent);
    $role = Role::query()->firstOrCreate(['name' => 'S11 Elision Member'], ['color' => 'role-agent', 'permissions' => ['tickets.view'], 'scopes' => ['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All']]);
    $agent->forceFill(['role_id' => $role->getKey()])->save();
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
    app(App\Domain\Inbox\Status\AgentStatus::class)->set($agent, 'online');

    [, $conversation] = conversationFixture();
    $ticket = app(App\Domain\Tickets\Creation\CreateTicket::class)->create(new App\Domain\Tickets\Creation\NewTicket($conversation, App\Domain\Tickets\Creation\TicketSource::Agent, departmentId: $payments));
    $result = app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $payments]);
    expect($result['outcome'])->toBe('assigned');
    $recordId = $result['record_id'];

    $supervisor = elisionActor(['tickets.view', 'reports.agents.individual'], 'S11 Elision Supervisor');
    $plain = elisionActor(['tickets.view'], 'S11 Elision Plain');

    $full = app(RecordReader::class)->forViewer($supervisor, $ticket->identifier(), $recordId);
    expect($full)->not->toBeNull()
        ->and($full['assigned_to'])->toBe($agent->identifier())
        ->and($full['names'][$agent->identifier()] ?? null)->toBe('Fixture Elision Agent Dawit', 'the drawn card renders NAMES — the map must resolve them');
    $fullJson = json_encode($full, JSON_THROW_ON_ERROR);
    expect(str_contains($fullJson, $agent->identifier()))->toBeTrue();

    $elided = app(RecordReader::class)->forViewer($plain, $ticket->identifier(), $recordId);
    expect($elided)->not->toBeNull();
    expect(array_key_exists('assigned_to', $elided))->toBeFalse('elision must be ABSENCE — the key itself may not exist')
        ->and(array_key_exists('names', $elided))->toBeFalse();
    $elidedJson = json_encode($elided, JSON_THROW_ON_ERROR);
    expect(str_contains($elidedJson, $agent->identifier()))->toBeFalse('an agent id leaked into the elided record')
        ->and(str_contains($elidedJson, 'Fixture Elision Agent Dawit'))->toBeFalse('an agent NAME leaked into the elided record');
    // The logic stays whole (C11's note): same steps, same outcomes, strategy, boosts.
    expect(array_column($elided['steps'], 'step'))->toBe(array_column($full['steps'], 'step'))
        ->and(array_column($elided['steps'], 'outcome'))->toBe(array_column($full['steps'], 'outcome'))
        ->and($elided['strategy'])->toBe($full['strategy'])
        ->and($elided['boosts_applied'])->toBe($full['boosts_applied'])
        ->and($elided['eligible_count'])->toBe($full['eligible_count']);

    // The HTTP door answers the same two shapes (SEC-01 re-check inside the reader).
    activateTenant($tenant);
    $doorFull = $this->actingAs($supervisor, 'staff')->getJson('https://localhost/inbox/t/'.$ticket->identifier().'/routing/'.$recordId);
    $doorFull->assertStatus(200);
    bindTenant($tenant);
    expect($doorFull->json('assigned_to'))->toBe($agent->identifier());
    $doorElided = $this->actingAs($plain, 'staff')->getJson('https://localhost/inbox/t/'.$ticket->identifier().'/routing/'.$recordId);
    $doorElided->assertStatus(200);
    bindTenant($tenant);
    expect($doorElided->json('assigned_to'))->toBeNull()->and(str_contains((string) $doorElided->getContent(), $agent->identifier()))->toBeFalse();
});
