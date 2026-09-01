<?php

declare(strict_types=1);

use App\Domain\Hours\CalendarWriter;
use App\Domain\Sla\Instances\InstanceArmer;
use App\Domain\Sla\Policies\PolicyWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 BR-03 / T-05 / EC-05 / AC-03's snapshot half — ARMED INSTANCES ARE
| CONTRACTS (the falsification, written BEFORE policy editing shipped)
|--------------------------------------------------------------------------
|
| Sabotage (d)'s standing red lives in TWO layers, both here:
|   1. PHYSICS — sla_instances holds NO UPDATE grant: the direct falsification
|      dies 42501 (re-proven from SlaGrantPhysicsTest so this file is
|      self-contained).
|   2. THE WRITER — PolicyWriter edits + CalendarWriter edits + holiday adds
|      leave an armed instance's snapshots and its stages' targets
|      STRUCTURALLY IDENTICAL: retroactive SLA rewriting is prohibited
|      (dispute integrity — SS-02 stands on this).
*/

function armedScene(): array
{
    [$tenant, , $ticket] = ticketFixture('new');
    $actor = (string) Str::uuid7();
    $calendarWriter = app(CalendarWriter::class);
    $weekdays = [];
    foreach ([1, 2, 3, 4, 5] as $d) {
        $weekdays[$d] = [['open' => '08:00', 'close' => '17:00']];
    }
    $calendarId = $calendarWriter->create('SLA calendar', 'Africa/Addis_Ababa', $weekdays, $actor, isTenantDefault: true);
    $policyId = app(PolicyWriter::class)->create([
        'name' => 'Everything', 'match_conditions' => [],
        'targets' => ['normal' => ['first_response_s' => 600, 'resolution_s' => 86400]],
        'clock_mode' => 'business_hours', 'calendar_id' => $calendarId, 'warning_pct' => 80,
    ], $actor);
    $eventId = publishTicketCreated($tenant, $ticket);
    app(InstanceArmer::class)->armForTicket($ticket->identifier(), $eventId);
    /** @var object{id: string, policy_snapshot: string, calendar_snapshot: ?string} $instance */
    $instance = DB::table('sla_instances')->where('ticket_id', $ticket->identifier())->first();

    return [$tenant, $ticket, $actor, $calendarId, $policyId, $instance];
}

it('BR-03 physics: the direct armed-instance falsification dies 42501', function (): void {
    armedScene();
    try {
        DB::transaction(static fn () => DB::statement("UPDATE sla_instances SET policy_snapshot = '{}'::jsonb"));
        $this->fail('an armed instance mutated — BR-03 physics gone');
    } catch (Illuminate\Database\QueryException $e) {
        expect($e->getMessage())->toContain('permission denied');
    }
});

it('BR-03/EC-05: policy edits, calendar edits and holiday adds are ALL prospective — the armed snapshots and stage targets never move', function (): void {
    [, $ticket, $actor, $calendarId, $policyId, $instance] = armedScene();
    $policyBefore = json_decode($instance->policy_snapshot, true);
    $calendarBefore = json_decode((string) $instance->calendar_snapshot, true);
    $targetsBefore = DB::table('sla_instance_stages')->where('instance_id', $instance->id)
        ->orderBy('stage')->pluck('target_us', 'stage')->all();

    app(PolicyWriter::class)->update($policyId, [
        'targets' => ['normal' => ['first_response_s' => 5, 'resolution_s' => 10]],
        'warning_pct' => 10,
    ], $actor);
    app(CalendarWriter::class)->update($calendarId, ['weekdays' => [6 => [['open' => '10:00', 'close' => '11:00']]]], $actor);
    app(CalendarWriter::class)->addHoliday($calendarId, '2027-01-07', 'Genna', true, $actor);

    /** @var object{policy_snapshot: string, calendar_snapshot: ?string} $after */
    $after = DB::table('sla_instances')->where('ticket_id', $ticket->identifier())->first();
    expect(json_decode($after->policy_snapshot, true))->toEqual($policyBefore, 'a policy edit reached an armed snapshot — BR-03 broken')
        ->and(json_decode((string) $after->calendar_snapshot, true))->toEqual($calendarBefore, 'a calendar edit reached an armed snapshot — EC-05 broken');
    expect(DB::table('sla_instance_stages')->where('instance_id', $instance->id)->orderBy('stage')->pluck('target_us', 'stage')->all())
        ->toEqual($targetsBefore, 'a stage target moved after a policy edit — retroactive rewriting');

    // The prospective half: a NEW ticket arms on the EDITED policy.
    $conversation = anotherConversation((string) DB::table('tickets')->where('id', $ticket->identifier())->value('tenant_id'), 'prospective');
    $newTicketId = (string) Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $newTicketId, 'tenant_id' => $conversation->getAttribute('tenant_id'),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $conversation->getAttribute('customer_id'),
        'number' => random_int(1, 2_000_000_000), 'status' => 'new', 'priority' => 'normal', 'source' => 'agent',
    ]);
    $eventId = DB::transaction(fn (): string => app(App\Support\Events\EventPublisher::class)->publish('ticket.created', [
        'tenant_id' => (string) $conversation->getAttribute('tenant_id'), 'ticket_id' => $newTicketId,
        'conversation_id' => $conversation->identifier(), 'actor_type' => 'system', 'actor_id' => null,
        'source' => 'agent', 'customer_id' => (string) $conversation->getAttribute('customer_id'),
    ]));
    app(InstanceArmer::class)->armForTicket($newTicketId, $eventId);
    /** @var object{policy_snapshot: string} $fresh */
    $fresh = DB::table('sla_instances')->where('ticket_id', $newTicketId)->first();
    $freshSnapshot = json_decode($fresh->policy_snapshot, true);
    expect($freshSnapshot['targets']['normal']['first_response_s'])->toBe(5, 'a new instance did not honor the edited policy — prospective means prospective');
});
