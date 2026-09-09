<?php

declare(strict_types=1);

use App\Domain\Sms\Failover\CircuitBreaker;
use App\Domain\Sms\Gateways\FixtureGatewaySeeder;
use App\Domain\Sms\Routing\RoutingTable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SmsSupport.php';

/*
| Doc 14 FR-09 / NFR-05 / SEC-04 — the circuit breaker: five consecutive
| gateway-level failures trip it (open, health down, opened_at stamped by the
| DB trigger); half-open after the cooldown with an INJECTED clock (≤ 30 s from
| trip); a success closes; a half-open failure re-opens; the health-probe sweep
| closes a probing profile; the manual reset needs a reason and is audited
| high-visibility; every transition is audited ONCE (transition-only).
*/

it('FR-09 / NFR-05: five gateway-level failures trip the circuit (open, health down, opened_at stamped by the DB) — the gateway drops out of every chain at the NEXT resolve; a success after half-open closes it; the manual reset needs a reason and is audited', function (): void {
    [, $user] = smsScene(['sms.gateways.view', 'sms.gateways.manage']);
    $seeded = app(FixtureGatewaySeeder::class)->seed('00000000-0000-7000-8000-0000000000aa');
    $breaker = app(CircuitBreaker::class);
    $table = app(RoutingTable::class);
    $geez = $seeded['geezsms'];

    for ($i = 1; $i <= 4; $i++) {
        expect($breaker->recordFailure($geez->refresh(), 'auth_rejected'))->toBeFalse();
    }
    expect($geez->refresh()->health)->toBe('degraded')->and($geez->circuit_state)->toBe('closed');
    expect($breaker->recordFailure($geez->refresh(), 'auth_rejected'))->toBeTrue('the fifth failure trips');
    $geez->refresh();
    expect($geez->circuit_state)->toBe('open')->and($geez->health)->toBe('down')->and($geez->circuit_opened_at)->not->toBeNull();

    // failover engages at the next resolve: the open gateway is not viable; the fallback carries
    $viable = $table->resolve('+251912345678')->viable();
    expect(array_column($viable, 'gateway_id'))->toBe([$seeded['reference']->identifier()]);

    // half-open after the cooldown (an injected clock) — the profile has no probe path: the next real send decides
    $tooEarly = $breaker->probe($geez->circuit_opened_at->add(new DateInterval('PT10S')));
    expect($tooEarly['half_opened'])->toBe(0)->and($geez->refresh()->circuit_state)->toBe('open');
    $counts = $breaker->probe($geez->circuit_opened_at->add(new DateInterval('PT31S')));
    expect($counts['half_opened'])->toBe(1)->and($geez->refresh()->circuit_state)->toBe('half_open');
    $breaker->recordSuccess($geez->refresh());
    expect($geez->refresh()->circuit_state)->toBe('closed')->and($geez->health)->toBe('healthy')->and($geez->circuit_opened_at)->toBeNull();

    // trip again; a half-open probe failure re-opens; the manual reset closes with its reason, audited high-visibility
    for ($i = 1; $i <= 5; $i++) {
        $breaker->recordFailure($geez->refresh(), 'unreachable');
    }
    $breaker->probe($geez->refresh()->circuit_opened_at->add(new DateInterval('PT31S')));
    expect($breaker->recordFailure($geez->refresh(), 'unreachable'))->toBeTrue()->and($geez->refresh()->circuit_state)->toBe('open');
    smsApi($user, 'POST', '/gateways/'.$geez->identifier().'/circuit/reset', ['reason' => ''])->assertStatus(422);
    smsApi($user, 'POST', '/gateways/'.$geez->identifier().'/circuit/reset', ['reason' => 'telco confirmed the auth key was rotated back'])->assertOk()->assertJsonPath('circuit_state', 'closed');
    $audit = DB::table('audit_log')->where('action_key', 'sms.gateway.circuit_reset')->first(['actor_type', 'payload']);
    expect($audit->actor_type)->toBe('staff')->and((string) $audit->payload)->toContain('rotated back');
    expect(DB::table('audit_log')->where('action_key', 'sms.gateway.circuit_changed')->where('actor_type', 'system')->count())->toBeGreaterThanOrEqual(4);
});

it('the reference profile\'s health probe closes a half-open circuit on the sweep; a gateway with no probe path is left for the next send', function (): void {
    [, $user] = smsScene(['sms.gateways.manage']);
    $seeded = app(FixtureGatewaySeeder::class)->seed('00000000-0000-7000-8000-0000000000aa');
    $breaker = app(CircuitBreaker::class);
    $reference = $seeded['reference'];
    for ($i = 1; $i <= 5; $i++) {
        $breaker->recordFailure($reference->refresh(), 'unreachable');
    }
    $openedAt = $reference->refresh()->circuit_opened_at;
    $counts = $breaker->probe($openedAt->add(new DateInterval('PT31S')));
    expect($counts)->toBe(['half_opened' => 1, 'closed' => 1, 'reopened' => 0])
        ->and($reference->refresh()->circuit_state)->toBe('closed');
});
