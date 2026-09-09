<?php

declare(strict_types=1);

use App\Domain\Sms\Gateways\FixtureGatewaySeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SmsSupport.php';

/*
| Doc 14 FR-07 / SEC-06 (read half) / STOP-P — the gateway door: the rate card
| reports.view-gated in every read (the spend/report half is step 11's
| SmsRateCardGateTest); the permission split; the fixture seeder's two FIXTURE
| profiles + the +251 route, idempotent, refused in production. The SEC-01
| write-only round-trip is tests/Security/SmsCredentialWriteOnlyTest.php.
*/

function smsGatewayScene(array $extra = []): array
{
    return smsScene(['sms.gateways.view', 'sms.gateways.manage', ...$extra]);
}

it('SEC-06: the rate card is reports.view-gated in the read; the permission split holds; the show door omits every secret', function (): void {
    [, $manager] = smsGatewayScene();
    $id = smsApi($manager, 'POST', '/gateways', ['name' => 'g', 'driver' => 'http_generic', 'config' => FixtureGatewaySeeder::geezsmsMapping('http://mock-sms:8082/geezsms/api/v1'), 'credential' => 'fixture-geezsms-token'])->assertStatus(201)->json('id');
    // the manager holds no reports.view → no rate card in the read
    expect(smsApi($manager, 'GET', '/gateways/'.$id)->assertOk()->json('config'))->not->toHaveKey('rate_card');

    // a second tenant scene whose manager also holds reports.view → the rate card is in the read
    [, $manager2] = smsScene(['sms.gateways.view', 'sms.gateways.manage', 'reports.view']);
    $id2 = smsApi($manager2, 'POST', '/gateways', ['name' => 'g2', 'driver' => 'http_generic', 'config' => FixtureGatewaySeeder::geezsmsMapping('http://mock-sms:8082/geezsms/api/v1'), 'credential' => 'fixture-geezsms-token'])->assertStatus(201)->json('id');
    $show = smsApi($manager2, 'GET', '/gateways/'.$id2)->assertOk();
    expect($show->json('config.rate_card.0.currency'))->toBe('ETB')
        ->and((string) $show->getContent())->not->toContain('vault://')->not->toContain('fixture-geezsms-token');

    [, $viewer] = smsScene(['sms.gateways.view']);
    smsApi($viewer, 'POST', '/gateways', ['name' => 'x', 'driver' => 'http_generic', 'config' => []])->assertForbidden();
});

it('the fixture seeder: two FIXTURE profiles + the +251 route (GeezSMS primary, reference fallback), idempotent; refuses in production', function (): void {
    [$tenant] = smsGatewayScene();
    $seeded = app(FixtureGatewaySeeder::class)->seed('00000000-0000-7000-8000-0000000000aa');
    expect($seeded['geezsms']->is_fixture)->toBeTrue()->and($seeded['reference']->is_fixture)->toBeTrue()
        ->and($seeded['route']->country_prefix)->toBe('+251')
        ->and($seeded['route']->primary_gateway_id)->toBe($seeded['geezsms']->identifier())
        ->and($seeded['route']->fallback_gateway_ids)->toBe([$seeded['reference']->identifier()]);
    $again = app(FixtureGatewaySeeder::class)->seed('00000000-0000-7000-8000-0000000000aa');
    expect($again['geezsms']->identifier())->toBe($seeded['geezsms']->identifier())
        ->and(DB::table('sms_gateways')->count())->toBe(2)
        ->and(DB::table('sms_gateways')->where('is_fixture', false)->count())->toBe(0, 'no non-fixture gateway row exists in a non-production seed (STOP-P)');

    app()->instance('env', 'production');
    try {
        expect(fn () => app(FixtureGatewaySeeder::class)->seed('00000000-0000-7000-8000-0000000000aa'))->toThrow(App\Domain\Sms\Gateways\GatewayRefused::class);
    } finally {
        app()->instance('env', 'testing');
    }
});
