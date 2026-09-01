<?php

declare(strict_types=1);

use App\Domain\Sla\Instances\StageLifecycle;
use App\Domain\Sla\Timers\SlaTimers;
use App\Domain\Sla\Timers\SlaTimerSweep;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-10 / T-04 / FS-01 / FS-02 / closing row 11 — the timers on Doc 06's
| T-03 infrastructure
|--------------------------------------------------------------------------
|
| - Next-fire-at candidates in the redis-durable ZSET; the sweep RE-VERIFIES
|   every fire against row truth + the clock (a stale member drops).
| - A due warn flips `warned` (the RIDER-1 trigger stamps detection time), the
|   EVENT carries the COMPUTED due_at + late_fired (FS-02's honesty split).
| - FS-02 DRILL: the ZSET is WIPED → nothing fires early → rebuild restores
|   candidates from row truth → the fire lands LATE and says so.
| - A paused stage carries NO candidate (the clock is stopped, not ticking).
| - Closing row 11: BoostMatcher's sla_at_risk reads THE REAL warned stage.
*/

function slaTimerScore(string $member): ?int
{
    $score = Redis::connection('durable')->zscore(SlaTimers::ZSET, $member);

    return $score === false || $score === null ? null : (int) $score;
}

function slaSweepUnbound(): array
{
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return app(SlaTimerSweep::class)->sweep();
}

it('T-04: arming computes next-fire candidates; a paused stage carries NO candidate', function (): void {
    [, $ticket, $instance] = timerScene();
    /** @var object{id: string} $stage */
    $stage = DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')->first();
    expect(slaTimerScore((string) $stage->id))->not->toBeNull('no candidate armed for a live stage');

    app(StageLifecycle::class)->onStatusChanged($ticket->identifier(), 'open', 'pending_customer', '2026-09-01 10:00:00+00');
    expect(slaTimerScore((string) $stage->id))->toBeNull('a paused stage still holds a candidate — the clock is ticking through a pause');
});

it('FR-10/NFR-02 structural + FS-02 honesty: a due warn AND breach fire once with the COMPUTED due_at; a redelivered sweep fires nothing twice', function (): void {
    [$tenant, $ticket, $instance] = timerScene(60);
    commitFixtures();
    // Backdate the clock so warn (48 s) and breach (60 s) are both long past —
    // clock_start_at is app-IMMUTABLE (the grant working), so the harness
    // backdates as the schema owner (the S11 superuser-flip pattern).
    DB::connection('pgsql_migrator')->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenant->identifier()]);
    DB::connection('pgsql_migrator')->table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')
        ->update(['clock_start_at' => DB::raw("clock_timestamp() - interval '10 minutes'")]);
    /** @var object{id: string} $stage */
    $stage = DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')->first();
    app(StageLifecycle::class)->recomputeTicket($ticket->identifier());
    commitFixtures();

    slaSweepUnbound();
    bindTenant($tenant);
    /** @var object{warned: bool, breached: bool, warned_at: ?string, breached_at: ?string, late_fired: bool} $after */
    $after = DB::table('sla_instance_stages')->where('id', $stage->id)->first();
    expect($after->warned)->toBeTrue()
        ->and($after->warned_at)->not->toBeNull('the RIDER-1 trigger did not stamp')
        ->and($after->breached)->toBeTrue()
        ->and($after->late_fired)->toBeTrue('a 10-minute-late fire was not flagged late_fired (FS-02)');
    /** @var object{payload: string} $warnEvent */
    $warnEvent = DB::table('outbox')->where('name', 'sla.warned')->orderByDesc('id')->first();
    $payload = json_decode($warnEvent->payload, true);
    expect($payload['stage'])->toBe('first_response')
        ->and($payload['late_fired'])->toBeTrue()
        ->and($payload['due_at'])->not->toBeNull();
    expect((int) DB::table('outbox')->where('name', 'sla.breached')->count())->toBe(1);

    // Redelivered sweep: candidates re-synced away — nothing fires twice.
    commitFixtures();
    slaSweepUnbound();
    bindTenant($tenant);
    expect((int) DB::table('outbox')->where('name', 'sla.warned')->count())->toBe(1)
        ->and((int) DB::table('outbox')->where('name', 'sla.breached')->count())->toBe(1);
});

it('FS-02 DRILL: the ZSET is wiped — nothing fires early; rebuild restores candidates from row truth and the late fire says so', function (): void {
    [$tenant, $ticket, $instance] = timerScene(60);
    commitFixtures();
    DB::connection('pgsql_migrator')->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenant->identifier()]);
    DB::connection('pgsql_migrator')->table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')
        ->update(['clock_start_at' => DB::raw("clock_timestamp() - interval '5 minutes'")]);
    commitFixtures();

    // THE LOSS: every candidate gone.
    Redis::connection('durable')->del(SlaTimers::ZSET);
    slaSweepUnbound();
    bindTenant($tenant);
    expect((int) DB::table('outbox')->where('name', 'sla.warned')->count())->toBe(0, 'a fire happened with no candidate — the sweep is scanning tables, not the ZSET');

    // THE RECOVERY: rebuild from row truth, then the sweep fires late-and-flagged.
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    app(SlaTimerSweep::class)->rebuild();
    slaSweepUnbound();
    bindTenant($tenant);
    /** @var object{payload: string} $event */
    $event = DB::table('outbox')->where('name', 'sla.warned')->orderByDesc('id')->first();
    expect($event)->not->toBeNull('rebuild did not restore the candidate (FS-02)')
        ->and(json_decode($event->payload, true)['late_fired'])->toBeTrue();
});

it('closing row 11: BoostMatcher\'s sla_at_risk reads the REAL warned stage — no projection field involved', function (): void {
    [$tenant, $ticket, $instance] = timerScene(60);
    $matcher = app(App\Domain\Routing\Pipeline\BoostMatcher::class);
    $row = (array) DB::table('tickets')->where('id', $ticket->identifier())->first();

    expect(in_array('sla_at_risk', $matcher->matches($row), true))->toBeFalse('an unwarned ticket boosted');
    DB::table('sla_instance_stages')->where('instance_id', $instance->id)->where('stage', 'first_response')->update(['warned' => true]);
    expect(in_array('sla_at_risk', $matcher->matches($row), true))->toBeTrue('a warned stage did not boost — closing row 11 not real');
});
