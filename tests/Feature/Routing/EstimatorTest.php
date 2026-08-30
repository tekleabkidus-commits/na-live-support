<?php

declare(strict_types=1);

use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Routing\Estimator\BandMapper;
use App\Domain\Routing\Estimator\Counters;
use App\Domain\Routing\Estimator\EstimateEndpoint;
use App\Domain\Routing\Estimator\Estimator;
use App\Domain\Routing\Estimator\QueueWaitVariable;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Jobs\Routing\AssignmentStatsConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-13 / FR-14 / T-06 / NFR-04 / FS-03 / SEC-05 / US-01 / AC-09 — the estimator
| (S11 step 8; A-52 filed). Every instant is INJECTED (DB time in production) — no
| assertion here depends on the wall clock.
|--------------------------------------------------------------------------
*/

/** @return array{0: string, 1: string, 2: string, 3: DateTimeImmutable} [tenantId, generalQueue, paymentsQueue, a daytime instant] */
function estimatorScene(): array
{
    $tenantId = createTenant()->identifier();
    app(OrganizationSeeder::class)->seed();
    $general = (string) DB::table('departments')->where('slug', 'general')->value('default_queue_id');
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('default_queue_id');
    $noon = new DateTimeImmutable('2026-09-01 12:00:00', app(App\Domain\Routing\Hours\HoursContract::class)->timezone());   // payments drawn 08–22 TENANT-LOCAL

    return [$tenantId, $general, $payments, $noon];
}

function waitingIn(string $queueId): App\Models\Ticket
{
    $departmentId = (string) DB::table('queues')->where('id', $queueId)->value('department_id');
    [, $conversation] = conversationFixture();
    $ticket = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $departmentId));
    app(RoutingPipeline::class)->route($ticket, 'created', ['explicit_department_id' => $departmentId, 'explicit_queue_id' => $queueId]);

    return $ticket->fresh();
}

it('FR-13 / A-52: bands from the raw estimate — position × 30 / assignments-per-30-min; no throughput data → the depth-only heuristic; thresholds from the setting', function () {
    [, $general, , $noon] = estimatorScene();
    $mapper = app(BandMapper::class);
    expect($mapper->thresholds())->toBe(['short' => 8, 'medium' => 35])
        ->and($mapper->rawMinutes(1, null))->toBe(10.0)                       // depth-only heuristic
        ->and($mapper->rawMinutes(4, 6))->toBe(20.0)
        ->and($mapper->rawMinutes(4, 30))->toBe(4.0)
        ->and($mapper->bandFor(4.0))->toBe('short')
        ->and($mapper->bandFor(20.0))->toBe('medium')
        ->and($mapper->bandFor(36.0))->toBe('long');

    // An empty queue with nobody assigned in the window: position 1, depth-only 10 min → medium, not degraded.
    expect(app(Estimator::class)->forQueue($general, $noon))->toBe(['band' => 'medium', 'degraded' => false]);

    // Three waiting, six assignments in the window: a new arrival is 4th → 20 min → medium; the 2nd ticket → 10 min → medium; thirty assignments → 4 min → short.
    $t1 = waitingIn($general);
    $t2 = waitingIn($general);
    waitingIn($general);
    $counters = app(Counters::class);
    $epoch = (int) $noon->format('U');
    for ($i = 0; $i < 6; $i++) {
        $counters->record($general, $epoch - $i * 60);
    }
    $d = app(Estimator::class)->detail($general, null, $noon);
    expect($d['position'])->toBe(4)->and($d['throughput30'])->toBe(6)->and($d['raw_minutes'])->toBe(20.0)->and($d['band'])->toBe('medium');
    expect(app(Estimator::class)->detail($general, $t2->identifier(), $noon)['position'])->toBe(2)
        ->and(app(Estimator::class)->detail($general, $t1->identifier(), $noon)['position'])->toBe(1);
    for ($i = 0; $i < 24; $i++) {
        $counters->record($general, $epoch - ($i % 29) * 60);
    }
    app(BandMapper::class)->forget($general);
    expect(app(Estimator::class)->forQueue($general, $noon))->toBe(['band' => 'short', 'degraded' => false]);
    // The window is 30 minutes: an assignment 31 minutes ago is not counted.
    expect($counters->throughput30($general, $epoch + 31 * 60))->toBe(0);
});

it('NFR-04 hysteresis: the shown band moves only when the raw estimate crosses by ≥ hysteresis_pct of the band width AND ≥ 30 s passed — no flapping around a boundary', function () {
    [, $general, , $noon] = estimatorScene();
    $mapper = app(BandMapper::class);
    $t0 = (int) $noon->format('U');
    expect($mapper->shownBand($general, 4.0, $t0))->toBe('short');
    // Flapping fixture: 7.9 / 8.1 / 7.9 / 9.5 (within 20 % of the short width = 1.6 above 8) → stays short.
    foreach ([7.9, 8.1, 7.9, 9.5, 8.1] as $i => $raw) {
        expect($mapper->shownBand($general, $raw, $t0 + 40 + $i))->toBe('short', 'flapped at raw '.$raw);
    }
    // Crossed with margin but inside the 30 s gate → still short; after the gate → medium.
    expect($mapper->shownBand($general, 12.0, $t0 + 10))->toBe('short')
        ->and($mapper->shownBand($general, 12.0, $t0 + 31))->toBe('medium');
    // Back down: 7.0 is inside 20 % of the medium width (27 × 0.2 = 5.4 below 8 → 2.6) → stays medium; 2.0 after the gate → short.
    expect($mapper->shownBand($general, 7.0, $t0 + 70))->toBe('medium')
        ->and($mapper->shownBand($general, 2.0, $t0 + 70))->toBe('short');
    // Up to long from medium: 38 is inside the margin above 35 (5.4) → medium; 41 → long.
    $mapper->forget($general);
    expect($mapper->shownBand($general, 20.0, $t0 + 200))->toBe('medium')
        ->and($mapper->shownBand($general, 38.0, $t0 + 240))->toBe('medium')
        ->and($mapper->shownBand($general, 41.0, $t0 + 240))->toBe('long');
});

it('FS-03: a corrupted counter bucket → depth-only heuristic with degraded=true (same vocabulary); routing:rebuild HEALS it', function () {
    [$tenantId, $general] = estimatorScene();
    $counters = app(Counters::class);
    // REAL DB time here: the heal runs on the command's own clock; General is always open, so the band is clock-independent.
    $noon = app(App\Domain\Routing\Hours\HoursContract::class)->now();
    $epoch = (int) $noon->format('U');
    for ($i = 0; $i < 30; $i++) {
        $counters->record($general, $epoch - ($i % 29) * 60);
    }
    app(BandMapper::class)->forget($general);
    expect(app(Estimator::class)->forQueue($general, $noon))->toBe(['band' => 'short', 'degraded' => false]);

    Redis::connection('volatile')->set($counters->bucketKey($general, intdiv($epoch, 60) - 3), 'garbage');
    expect($counters->throughput30($general, $epoch))->toBeNull();
    app(BandMapper::class)->forget($general);
    expect(app(Estimator::class)->forQueue($general, $noon))->toBe(['band' => 'medium', 'degraded' => true]);   // depth-only: 1 × 10 min

    Illuminate\Support\Facades\Artisan::call('routing:rebuild');
    expect(Illuminate\Support\Facades\Artisan::output())->toContain('FS-03 heal');
    app(BandMapper::class)->forget($general);
    expect($counters->throughput30($general, $epoch))->toBe(0)
        ->and(app(Estimator::class)->forQueue($general, $noon))->toBe(['band' => 'medium', 'degraded' => false]);
});

it('T-06: the cached endpoint answers {band, degraded} — nothing else in the shape — and holds its answer within cache_seconds; the counters are fed from ticket.assigned through the consumer, deduplicated per event id', function () {
    [$tenantId, $general, , $noon] = estimatorScene();
    $first = app(EstimateEndpoint::class)->forQueue($general);
    expect(array_keys($first))->toBe(['band', 'degraded']);
    // State changes underneath; the cached answer stands (≤ 30 s staleness, NFR-04).
    for ($i = 0; $i < 30; $i++) {
        app(Counters::class)->record($general, (int) $noon->format('U'));
    }
    expect(app(EstimateEndpoint::class)->forQueue($general))->toBe($first);

    // The consumer path: a REAL ticket.assigned event, relayed, counts once — and once only on redelivery.
    $ticket = waitingIn($general);
    $agent = ticketActor(['tickets.view', 'tickets.assign', 'tickets.assign_self', 'tickets.status.change'], 'Fixture Estimator Agent');
    app(AssignTicket::class)->claim($ticket, $agent);
    $eventId = relayOutboxRow('ticket.assigned');
    /** @var object{s: string} $clock */
    $clock = DB::selectOne('SELECT EXTRACT(EPOCH FROM clock_timestamp())::bigint AS s');
    $before = app(Counters::class)->throughput30($general, (int) $clock->s);
    (new AssignmentStatsConsumer($eventId))->handle(app(App\Domain\Routing\Capacity\AssignmentStats::class), app(Counters::class));
    (new AssignmentStatsConsumer($eventId))->handle(app(App\Domain\Routing\Capacity\AssignmentStats::class), app(Counters::class));
    expect(app(Counters::class)->throughput30($general, (int) $clock->s))->toBe($before + 1, 'C-09: the redelivered event double-counted (or the assignment did not count)');
});

it('FR-14 / US-01: the {{queue_wait}} provider gives the localized BAND PHRASE — a waiting ticket by its own position, a pre-ticket conversation by the tenant default queue, off-hours as "when we open at HH:MM" (en + am)', function () {
    [, $general, $payments, $noon] = estimatorScene();
    $provider = app(QueueWaitVariable::class);
    // No ticket yet → the General default queue, depth-only → medium.
    expect($provider->forConversation((string) Illuminate\Support\Str::uuid7(), 'en'))->toBe(__('routing.estimate.band.medium', [], 'en'))
        ->and($provider->forConversation((string) Illuminate\Support\Str::uuid7(), 'am'))->toBe(__('routing.estimate.band.medium', [], 'am'))
        ->and(__('routing.estimate.band.medium', [], 'am'))->not->toBe('routing.estimate.band.medium');
    // A waiting ticket: its own position drives the band.
    $ticket = waitingIn($general);
    expect($provider->forConversation($ticket->conversation_id, 'en'))->toBe(__('routing.estimate.band.medium', [], 'en'));
    // Off hours (payments 08–22 tenant-local): the phrase names the opening time.
    $tz = app(App\Domain\Routing\Hours\HoursContract::class)->timezone();
    $night = new DateTimeImmutable('2026-09-01 23:30:00', $tz);
    $detail = app(Estimator::class)->detail($payments, null, $night);
    expect($detail['band'])->toBe('off_hours')
        ->and((new DateTimeImmutable((string) $detail['opens_at']))->setTimezone($tz)->format('Y-m-d H:i'))->toBe('2026-09-02 08:00');
    expect($provider->phrase('off_hours', 'en', $detail['opens_at']))->toBe('when we open at 08:00')
        ->and($provider->phrase('off_hours', 'am', $detail['opens_at']))->toContain('08:00');
    // Inside hours the same queue bands normally.
    expect(app(Estimator::class)->detail($payments, null, $noon)['band'])->not->toBe('off_hours');
});
