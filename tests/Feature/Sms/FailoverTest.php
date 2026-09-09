<?php

declare(strict_types=1);

use App\Domain\Sms\Failover\CircuitBreaker;
use App\Domain\Sms\Gateways\Drivers\HttpGenericDriver;
use App\Domain\Sms\Gateways\FixtureGatewaySeeder;
use App\Domain\Sms\Gateways\GatewayConfig;
use App\Domain\Sms\Gateways\RenderedMessage;
use App\Domain\Sms\Routing\RoutingTable;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SmsSupport.php';

/*
| Doc 14 FR-08 / FR-09 / EC-01 / EC-12 (ladder half) / FS-01 / AC-03 — the
| routing table over the seeded rows, the no-match ladder (default gateway |
| skip), the "test a number" door, and the FS-01 auth-expiry ladder: real
| 401s from the mock reference profile classify gateway-level, trip the
| circuit, and the gateway drops out of every chain at the NEXT resolve (the
| per-message fallback walk itself is the pipeline's, step 5).
*/

it('resolves the seeded +251 route (GeezSMS primary, reference fallback), a +2519 row shadows it with the warning, and the no-match ladder answers default_gateway | skip', function (): void {
    [, $user] = smsScene(['sms.gateways.view', 'sms.gateways.manage']);
    $seeded = app(FixtureGatewaySeeder::class)->seed('00000000-0000-7000-8000-0000000000aa');
    $table = app(RoutingTable::class);

    $et = $table->resolve('+251912345678');
    expect($et->matchedPrefix)->toBe('+251')->and($et->noRoute)->toBeFalse()
        ->and(array_column($et->chain, 'gateway_id'))->toBe([$seeded['geezsms']->identifier(), $seeded['reference']->identifier()])
        ->and($et->chain[1]['sender_id'])->toBe('NABET', 'the reference profile\'s per-country sender applies on that hop');

    // a narrower row shadows the country row — the resolver says so and the door warns
    smsApi($user, 'POST', '/routes', ['country_prefix' => '+2519', 'primary_gateway_id' => $seeded['reference']->identifier(), 'sender_id_override' => 'NABET-M'])->assertStatus(201);
    $mobile = $table->resolve('+251912345678');
    expect($mobile->matchedPrefix)->toBe('+2519')->and($mobile->chain[0]['gateway_id'])->toBe($seeded['reference']->identifier())->and($mobile->chain[0]['sender_id'])->toBe('NABET-M');
    expect($table->overlaps())->toBe([['shadowing' => '+2519', 'shadowed' => '+251']]);

    // no match: the tenant default (when set) else no_default_gateway; the explicit skip policy
    expect($table->resolve('+254712345678')->reason)->toBe('no_default_gateway');
    app(SettingsRepository::class)->set('sms.default_gateway_id', $seeded['reference']->identifier());
    $default = $table->resolve('+254712345678');
    expect($default->reason)->toBe('tenant_default')->and($default->chain[0]['gateway_id'])->toBe($seeded['reference']->identifier())->and($default->matchedPrefix)->toBeNull();
    app(SettingsRepository::class)->set('sms.route.no_match', 'skip');
    expect($table->resolve('+254712345678'))->toHaveProperty('noRoute', true);

    // the "test a number" door (§8): the exact chain, named, with the overlaps
    $explained = smsApi($user, 'POST', '/gateways/resolve', ['phone' => '+251912345678'])->assertOk();
    expect($explained->json('matched_prefix'))->toBe('+2519')->and($explained->json('chain.0.gateway_name'))->toBe('Reference aggregator (FIXTURE profile)')->and($explained->json('overlaps.0.shadowing'))->toBe('+2519');
});

it('FS-01 auth-expiry ladder: five REAL 401s through the mock (a rotated token) classify gateway-level → the circuit trips → the gateway leaves every chain at the next resolve → the fallback carries; the transition is audited once', function (): void {
    smsScene(['sms.gateways.manage']);
    $seeded = app(FixtureGatewaySeeder::class)->seed('00000000-0000-7000-8000-0000000000aa');
    $reference = $seeded['reference'];
    $breaker = app(CircuitBreaker::class);
    $table = app(RoutingTable::class);
    $driver = new HttpGenericDriver;
    $config = GatewayConfig::fromStored($reference->config);

    for ($i = 1; $i <= 5; $i++) {
        $result = $driver->send(new RenderedMessage('m-'.$i, '+251900001234', 'hi', 'NABET', null), $config, 'fixture-rotated-away');
        expect($result->accepted)->toBeFalse()->and($result->gatewayLevel)->toBeTrue()->and($result->code)->toBe('auth_rejected');
        $breaker->recordFailure($reference->refresh(), $result->code);
    }
    expect($reference->refresh()->circuit_state)->toBe('open');
    $viable = $table->resolve('+251912345678')->viable();
    expect(array_column($viable, 'gateway_id'))->toBe([$seeded['geezsms']->identifier()], 'the tripped gateway is out of the chain; the primary still carries');
    expect(DB::table('audit_log')->where('action_key', 'sms.gateway.circuit_changed')->count())->toBe(1, 'closed→open is audited ONCE — transition-only, never per failure');
});
