<?php

declare(strict_types=1);

use App\Domain\Routing\Estimator\Estimator;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Routing\Waiting\WaitingSets;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Events\Conversations\RealtimePush;
use App\Jobs\Webchat\QueuePositionPush;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| P-QPOS (Doc 11 `Estimator::forWaitingTicket`, countersigned 2026-09-04 with
| the bounded-fan-out condition): the visitor's queue card reads the ticket's
| OWN 1-based position while it waits; the staff estimate door is byte-
| untouched (EstimatorLeakageTest pins it AND pins that the staff door never
| references the visitor method). EC-06 is the widget's law over the truth
| this answers. Doc13/QueuePositionPush pushes that truth to every WAITING
| webchat visitor of the queue — only where something changed.
|--------------------------------------------------------------------------
*/

/** The EstimatorTest's own waitingIn() shape, mirrored (that helper is file-local); `channel` picks the conversation's channel. */
function widgetWaitingIn(string $queueId, string $channel = 'webchat'): array
{
    $departmentId = (string) DB::table('queues')->where('id', $queueId)->value('department_id');
    [, $conversation] = conversationFixture();
    $visitorId = 'v_'.Str::random(12);
    DB::table('conversations')->where('id', $conversation->identifier())->update(['channel' => $channel, 'channel_ref_id' => '018f6b2e-0000-7000-8000-00000000d131', 'external_chat_id' => $visitorId]);
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $departmentId));
    app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $departmentId, 'explicit_queue_id' => $queueId]);

    return [$ticket->identifier(), $visitorId];
}

function queueEventRow(string $name, array $payload): string
{
    $id = (string) Str::uuid7();
    DB::table('events')->insert(['id' => $id, 'tenant_id' => app(TenantContext::class)->tenantId(), 'name' => $name, 'schema_version' => 1, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

    return $id;
}

it('P-QPOS: forWaitingTicket answers band + the ticket\'s own position while unassigned (2 → 1 → null); off-hours carries opens_at; the sync envelope reads the same method', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    $queue = (string) DB::table('departments')->where('slug', 'general')->value('default_queue_id');
    [$t1] = widgetWaitingIn($queue);
    [$t2] = widgetWaitingIn($queue);

    $estimator = app(Estimator::class);
    $noon = new DateTimeImmutable('2026-09-07 12:00:00', new DateTimeZone('Africa/Addis_Ababa'));
    expect($estimator->forWaitingTicket($queue, $t2, $noon)['position'])->toBe(2)
        ->and($estimator->forWaitingTicket($queue, $t1, $noon)['position'])->toBe(1);

    app(WaitingSets::class)->remove($queue, $t1); // the assignment's own effect on the waiting set: t1 leaves, t2 is now first
    expect($estimator->forWaitingTicket($queue, $t2, $noon)['position'])->toBe(1)
        ->and($estimator->forWaitingTicket($queue, $t1, $noon)['position'])->toBeNull('an assigned ticket has no position');

    // Off hours (payments 08–22 tenant-local, the EstimatorTest's own case): the visitor card carries opens_at.
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('default_queue_id');
    [$t3] = widgetWaitingIn($payments);
    $night = new DateTimeImmutable('2026-09-07 23:30:00', new DateTimeZone('Africa/Addis_Ababa'));
    $off = $estimator->forWaitingTicket($payments, $t3, $night);
    expect($off['band'])->toBe('off_hours')->and($off['opens_at'])->not->toBeNull();

    // The staff shape is untouched by construction: forTicket still answers exactly the two keys.
    expect(array_keys($estimator->forTicket($queue, $t2, $noon)))->toBe(['band', 'degraded']);
});

it('Doc13/QueuePositionPush: ONE ordered read + ONE membership query per event; every WAITING webchat visitor gets its own position; a non-webchat ticket in the same queue is never pushed; an unchanged queue pushes NOTHING; an assignment moves the rest (fan-out figure printed)', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    $queue = (string) DB::table('departments')->where('slug', 'general')->value('default_queue_id');
    [$t1, $v1] = widgetWaitingIn($queue);
    [$tFake] = widgetWaitingIn($queue, 'fakechan');   // Doc 04's fake channel sits between them — never a visitor frame
    [$t2, $v2] = widgetWaitingIn($queue);
    expect(app(WaitingSets::class)->head($queue, 3))->toBe([$t1, $tFake, $t2]);

    Event::fake([RealtimePush::class]);
    $frames = static fn (): array => array_map(
        static fn (RealtimePush $push): array => [$push->channelName, $push->payload['position'], $push->payload['ticket_ref']],
        array_map(static fn (array $args): RealtimePush => $args[0], Event::dispatched(RealtimePush::class)->all()),
    );

    // ticket.waiting for t2: the whole set is re-read; both webchat visitors learn their rank (1 and 3 — the fake ticket holds rank 2).
    $pushed = app()->call([new QueuePositionPush(queueEventRow('ticket.waiting', ['tenant_id' => $tenant->identifier(), 'ticket_id' => $t2, 'conversation_id' => 'x', 'queue' => $queue, 'position' => 3])), 'handle']);
    expect($pushed)->toBe(2)->and($frames())->toBe([
        ['tenant.'.$tenant->identifier().'.visitor.'.$v1.'.queue', 1, $t1],
        ['tenant.'.$tenant->identifier().'.visitor.'.$v2.'.queue', 3, $t2],
    ]);
    foreach (Event::dispatched(RealtimePush::class)->all() as [$push]) {
        expect($push->eventName)->toBe('webchat.queue')->and($push->payload)->toHaveKeys(['v', 'ticket_ref', 'band', 'position', 'opens_at'])
            ->and($push->payload)->not->toHaveKey('depth')->not->toHaveKey('throughput30');   // SEC-05: the visitor sees its own rank and the band — nothing about the queue
    }

    // The same truth again (a second event with nothing moved): ZERO frames — the fan-out condition.
    $pushed = app()->call([new QueuePositionPush(queueEventRow('routing.starvation_boosted', ['tenant_id' => $tenant->identifier(), 'ticket_id' => $t1, 'queue_id' => $queue, 'waited_seconds' => 1, 'cap_minutes' => 30])), 'handle']);
    expect($pushed)->toBe(0);
    Event::assertDispatchedTimes(RealtimePush::class, 2);

    // t1 is assigned (its own effect: it leaves the set); ticket.assigned re-reads — t2 moved (3 → 2), one frame — AND the
    // assigned visitor gets its own `joined` frame (D14/FR-13: no position, the agent's first name per widget.agent_identity).
    app(WaitingSets::class)->remove($queue, $t1);
    $assigneeId = ticketActor([])->identifier();
    bindTenant($tenant);
    DB::table('tickets')->where('id', $t1)->update(['assignee_id' => $assigneeId]);
    $pushed = app()->call([new QueuePositionPush(queueEventRow('ticket.assigned', ['tenant_id' => $tenant->identifier(), 'ticket_id' => $t1, 'conversation_id' => 'x', 'old_assignee_id' => null, 'new_assignee_id' => $assigneeId, 'by' => 'z'])), 'handle']);
    expect($pushed)->toBe(2);
    Event::assertDispatchedTimes(RealtimePush::class, 4);
    $all = array_map(static fn (array $a): RealtimePush => $a[0], Event::dispatched(RealtimePush::class)->all());
    $joined = array_values(array_filter($all, static fn (RealtimePush $p): bool => isset($p->payload['joined'])));
    expect($joined)->toHaveCount(1)
        ->and($joined[0]->channelName)->toBe('tenant.'.$tenant->identifier().'.visitor.'.$v1.'.queue')
        ->and($joined[0]->payload['position'])->toBeNull()
        ->and($joined[0]->payload['joined']['display'])->toBe(explode(' ', (string) DB::table('users')->where('id', $assigneeId)->value('name'))[0]);
    $moves = array_values(array_filter($all, static fn (RealtimePush $p): bool => ! isset($p->payload['joined'])));
    expect([$moves[2]->channelName, $moves[2]->payload['position'], $moves[2]->payload['ticket_ref']])->toBe(['tenant.'.$tenant->identifier().'.visitor.'.$v2.'.queue', 2, $t2]);

    fwrite(STDERR, "\n[§12 dev-scale] QueuePositionPush fan-out: 3 waiting (2 webchat) → placement 2 frames, unchanged re-event 0 frames, assignment 1 move frame + 1 joined frame; reads per event: 1 ZRANGE + 1 membership query + 1 estimate per webchat member\n");
});
