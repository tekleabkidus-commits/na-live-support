<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Eligibility\RoutableAgents;
use App\Domain\Routing\Estimator\Estimator;
use App\Domain\Routing\Hours\HoursContract;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-14 / EC-09 / A-53 — the hours contract ([D12], fixture-shaped) and the NO-CLIFF
| law: hours reach the estimator band and the pull-in default, NEVER eligibility.
|--------------------------------------------------------------------------
*/

it('FR-14 / A-53: the fixture-shaped contract — default always open, the payments override 08–22 tenant-local; isOpen/nextOpen/shiftEnd on explicit instants', function () {
    createTenant();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $general = (string) DB::table('departments')->where('slug', 'general')->value('id');
    $hours = app(HoursContract::class);
    $tz = $hours->timezone();   // the tenant's (Doc 01) — instants are built tenant-local, compared tenant-local
    $noon = new DateTimeImmutable('2026-09-01 12:00:00', $tz);
    $night = new DateTimeImmutable('2026-09-01 23:30:00', $tz);
    $dawn = new DateTimeImmutable('2026-09-01 06:00:00', $tz);

    expect($hours->windowFor($general)['close'])->toBe('24:00')
        ->and($hours->isOpen($general, $night))->toBeTrue()
        ->and($hours->isOpen($payments, $noon))->toBeTrue()
        ->and($hours->isOpen($payments, $night))->toBeFalse()
        ->and($hours->isOpen($payments, $dawn))->toBeFalse()
        ->and($hours->nextOpen($payments, $night)->setTimezone($tz)->format('Y-m-d H:i'))->toBe('2026-09-02 08:00')
        ->and($hours->nextOpen($payments, $dawn)->setTimezone($tz)->format('Y-m-d H:i'))->toBe('2026-09-01 08:00')
        ->and($hours->nextOpen($payments, $noon))->toEqual($noon)
        ->and($hours->nextOpen($general, $night))->toEqual($night);
    // A-53's default: the drawn close when ahead, else 23:59:59 tenant-local — computed from DB time, in the future.
    $end = $hours->shiftEnd($payments);
    expect($end > $hours->now())->toBeTrue()
        ->and(in_array($end->setTimezone($hours->timezone())->format('H:i:s'), ['22:00:00', '23:59:59'], true))->toBeTrue();
});

it('EC-09 NO CLIFF: outside the department\'s hours an Online agent is STILL assigned — RoutableAgents takes no HoursContract, the pipeline never asks; only the band says off_hours', function () {
    createTenant();
    app(OrganizationSeeder::class)->seed();
    $paymentsDept = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $paymentsDept)->value('default_queue_id');
    $admin = ticketActor(['org.queues.manage'], 'Fixture Hours Admin');
    $agent = ticketActor(['tickets.view'], 'Fixture Night Agent');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
    app(AgentStatus::class)->set($agent, 'online');
    $night = new DateTimeImmutable('2026-09-01 23:30:00', app(HoursContract::class)->timezone());

    expect(app(Estimator::class)->detail($queue, null, $night)['band'])->toBe('off_hours');
    $ctor = (new ReflectionClass(RoutableAgents::class))->getConstructor();
    expect(array_map(fn (ReflectionParameter $p) => (string) $p->getType(), $ctor?->getParameters() ?? []))->not->toContain(HoursContract::class);
    expect(array_column(app(RoutableAgents::class)->forQueue($queue), 'user_id'))->toBe([$agent->identifier()]);

    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $paymentsDept));
    $result = app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $paymentsDept]);
    expect($result['outcome'])->toBe('assigned')->and($result['assigned_to'])->toBe($agent->identifier());
    // The pipeline's source never mentions hours at all — the no-cliff law is structural.
    $pipeline = (string) file_get_contents(app_path('Domain/Routing/Pipeline/RoutingPipeline.php'));
    expect(str_contains($pipeline, 'HoursContract'))->toBeFalse('the pipeline consults hours — EC-09 cliff risk');
});
