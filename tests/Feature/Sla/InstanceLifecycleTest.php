<?php

declare(strict_types=1);

use App\Domain\Sla\Instances\InstanceArmer;
use App\Domain\Sla\Instances\StageLifecycle;
use App\Domain\Sla\Policies\PolicyWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-08 / FR-09 / EC-02 / EC-03 / EC-04-adjacent — arming, stages,
| pauses, retarget
|--------------------------------------------------------------------------
|
| - FR-08 first-match by (position, id); the vocabulary walls refuse a queue.
| - FR-09: first_response + resolution arm at creation; next_response arms per
|   customer message IDEMPOTENTLY WHILE LIVE (EC-02: the FIRST unanswered
|   message's clock stands under five rapid messages); a staff public reply
|   clears both response stages; resolved clears resolution; a reopen re-arms
|   it fresh.
| - Pauses: recorded {from, until, reason} intervals per the SNAPSHOT's
|   toggles; a redelivered open never doubles (C-09 → EC-04's union has
|   nothing to double-subtract).
| - EC-03: retarget_remaining stores the WHOLE formula record; keep stores
|   nothing.
*/

function slaScene(array $policy = []): array
{
    [$tenant, , $ticket] = ticketFixture('new');
    $actor = (string) Str::uuid7();
    $policyId = app(PolicyWriter::class)->create(array_merge([
        'name' => 'Base', 'match_conditions' => [],
        'targets' => [
            'normal' => ['first_response_s' => 600, 'next_response_s' => 900, 'resolution_s' => 86400],
            'high' => ['first_response_s' => 300, 'next_response_s' => 450, 'resolution_s' => 43200],
        ],
        'clock_mode' => 'calendar',
    ], $policy), $actor);
    $eventId = publishTicketCreated($tenant, $ticket);
    app(InstanceArmer::class)->armForTicket($ticket->identifier(), $eventId);
    /** @var object{id: string} $instance */
    $instance = DB::table('sla_instances')->where('ticket_id', $ticket->identifier())->first();

    return [$tenant, $ticket, $instance, $policyId];
}

it('FR-08/FR-09: the FIRST matching policy arms one instance with snapshots; first_response + resolution stages live; a redelivery arms nothing', function (): void {
    [$tenant, $ticket, $instance] = slaScene();
    expect($instance)->not->toBeNull();
    $stages = DB::table('sla_instance_stages')->where('instance_id', $instance->id)->whereNull('cleared_at')->pluck('stage')->all();
    expect($stages)->toEqualCanonicalizing(['first_response', 'resolution']);

    // Redelivery: the unique holds.
    $eventId = publishTicketCreated($tenant, $ticket);
    app(InstanceArmer::class)->armForTicket($ticket->identifier(), $eventId);
    expect((int) DB::table('sla_instances')->where('ticket_id', $ticket->identifier())->count())->toBe(1);
    expect((int) DB::table('outbox')->where('name', 'sla.instance.armed')->count())->toBe(1);
});

it('FR-08 walls: a queue key is refused (the vocabulary is closed); first-match wins by position', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $actor = (string) Str::uuid7();
    expect(fn () => app(PolicyWriter::class)->create(['name' => 'X', 'match_conditions' => ['queue_id' => 'q'], 'targets' => ['normal' => ['first_response_s' => 60]], 'clock_mode' => 'calendar'], $actor))
        ->toThrow(InvalidArgumentException::class, 'match vocabulary');

    // Two matching policies: position order picks the FIRST.
    app(PolicyWriter::class)->create(['name' => 'First', 'targets' => ['normal' => ['first_response_s' => 111]], 'clock_mode' => 'calendar'], $actor);
    app(PolicyWriter::class)->create(['name' => 'Second', 'targets' => ['normal' => ['first_response_s' => 222]], 'clock_mode' => 'calendar'], $actor);
    $conversation = anotherConversation($tenant->identifier(), 'first-match');
    $ticketId = (string) Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $ticketId, 'tenant_id' => $tenant->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $conversation->getAttribute('customer_id'),
        'number' => random_int(1, 2_000_000_000), 'status' => 'new', 'priority' => 'normal', 'source' => 'agent',
    ]);
    $eventId = DB::transaction(fn (): string => app(App\Support\Events\EventPublisher::class)->publish('ticket.created', [
        'tenant_id' => $tenant->identifier(), 'ticket_id' => $ticketId,
        'conversation_id' => $conversation->identifier(), 'actor_type' => 'system', 'actor_id' => null,
        'source' => 'agent', 'customer_id' => (string) $conversation->getAttribute('customer_id'),
    ]));
    app(InstanceArmer::class)->armForTicket($ticketId, $eventId);
    /** @var object{policy_snapshot: string} $instance */
    $instance = DB::table('sla_instances')->where('ticket_id', $ticketId)->first();
    expect(json_decode($instance->policy_snapshot, true)['targets']['normal']['first_response_s'])->toBe(111);
});

it('EC-02: five rapid customer messages hold ONE live next_response with the FIRST clock; a staff reply clears both response stages', function (): void {
    [, $ticket, $instance] = slaScene();
    $stages = app(StageLifecycle::class);
    $first = '2026-09-01 10:00:00+00';
    $stages->onCustomerMessage($ticket->identifier(), $first);
    for ($i = 1; $i <= 4; $i++) {
        $stages->onCustomerMessage($ticket->identifier(), '2026-09-01 10:0'.$i.':00+00');
    }
    $live = DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'next_response')->whereNull('cleared_at')->get();
    expect($live)->toHaveCount(1)
        ->and((new DateTimeImmutable((string) $live[0]->clock_start_at))->format('H:i'))->toBe('10:00', 'the re-arm moved the clock — EC-02 broken');

    $stages->onStaffReply($ticket->identifier());
    $cleared = DB::table('sla_instance_stages')->where('instance_id', $instance->id)
        ->whereIn('stage', ['first_response', 'next_response'])->whereNull('cleared_at')->count();
    expect((int) $cleared)->toBe(0, 'a staff public reply did not clear the response stages');
    expect((int) DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'resolution')->whereNull('cleared_at')->count())->toBe(1);
});

it('pauses per the snapshot toggles: pending_customer opens/closes a recorded interval; resolved clears resolution; a reopen re-arms it', function (): void {
    [, $ticket, $instance] = slaScene();
    $stages = app(StageLifecycle::class);

    $stages->onStatusChanged($ticket->identifier(), 'open', 'pending_customer', '2026-09-01 10:00:00+00');
    /** @var object{pauses: string} $row */
    $row = DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'resolution')->first();
    $pauses = json_decode($row->pauses, true);
    expect($pauses)->toHaveCount(1)
        ->and($pauses[0]['reason'])->toBe('pending_customer')
        ->and($pauses[0]['until'])->toBeNull();

    // Redelivery never doubles the open interval (C-09 → EC-04's input stays honest).
    $stages->onStatusChanged($ticket->identifier(), 'open', 'pending_customer', '2026-09-01 10:00:00+00');
    $row = DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'resolution')->first();
    expect(json_decode($row->pauses, true))->toHaveCount(1);

    $stages->onStatusChanged($ticket->identifier(), 'pending_customer', 'open', '2026-09-01 11:00:00+00');
    $row = DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'resolution')->first();
    expect(json_decode($row->pauses, true)[0]['until'])->not->toBeNull();

    $stages->onStatusChanged($ticket->identifier(), 'open', 'resolved', '2026-09-01 12:00:00+00');
    expect((int) DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'resolution')->whereNull('cleared_at')->count())->toBe(0);
    $stages->onStatusChanged($ticket->identifier(), 'resolved', 'open', '2026-09-01 13:00:00+00');
    $rearmed = DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'resolution')->whereNull('cleared_at')->get();
    expect($rearmed)->toHaveCount(1)
        ->and((new DateTimeImmutable((string) $rearmed[0]->clock_start_at))->format('H:i'))->toBe('13:00');
});

it('EC-03: retarget_remaining stores the documented formula record; keep stores nothing', function (): void {
    [, $ticket, $instance] = slaScene(['retarget_mode' => 'retarget_remaining']);
    $stages = app(StageLifecycle::class);
    $stages->onPriorityChanged($ticket->identifier(), 'high');
    /** @var object{retargeted: ?string, target_us: string|int} $row */
    $row = DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')->first();
    expect($row->retargeted)->not->toBeNull('retarget_remaining recorded nothing (EC-03)');
    $record = json_decode((string) $row->retargeted, true);
    expect($record['new_target_us'])->toBe(300_000_000)
        ->and($record['old_target_us'])->toBe(600_000_000)
        // The formula itself: remaining_after = remaining_pct × new_target.
        ->and($record['remaining_after_us'])->toBe((int) round(($record['remaining_before_us'] / $record['old_target_us']) * $record['new_target_us']))
        ->and((int) $row->target_us)->toBe(600_000_000); // the NOMINAL target never rewrites (grant physics)

    [, $ticket2, $instance2] = slaScene(['name' => 'Keep', 'retarget_mode' => 'keep']);
    app(StageLifecycle::class)->onPriorityChanged($ticket2->identifier(), 'high');
    expect(DB::table('sla_instance_stages')->where('instance_id', $instance2->id)->where('stage', 'first_response')->value('retargeted'))->toBeNull();
});
