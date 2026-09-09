<?php

declare(strict_types=1);

use App\Domain\Sms\Gateways\Drivers\HttpGenericDriver;
use App\Domain\Sms\Gateways\FixtureGatewaySeeder;
use App\Domain\Sms\Gateways\GatewayConfig;
use App\Domain\Sms\Gateways\GatewayCredentials;
use App\Domain\Sms\Gateways\RenderedMessage;
use App\Domain\Sms\State\DlrStatus;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/SmsSupport.php';

/*
| Doc 14 AC-08 / T-02 / FR-10 / §21 — `http_generic` config-mapped onboarding of
| the mock local aggregator WITHOUT code changes: the GeezSMS FIXTURE profile
| is onboarded by the seeded MAPPING alone (no file under Drivers/ names the
| vendor); the send reaches the REAL mock container through the egress proxy
| with the token as a form field (never a URL); the DLR payload parses by the
| mapping (`success` → ACCEPTED, never delivered — R-D14-1.5); the vendor cap
| and the template marker are refused before a byte leaves.
*/

it('AC-08: no code names the vendor — the mapping is the whole onboarding', function (): void {
    $dir = base_path('app/Domain/Sms/Gateways/Drivers');
    foreach (glob($dir.'/*.php') ?: [] as $file) {
        expect(strtolower((string) file_get_contents($file)))->not->toContain('geezsms', basename($file).' must not know the vendor');
    }
    expect(strtolower((string) file_get_contents(base_path('app/Domain/Sms/Gateways/GatewayConfig.php'))))->not->toContain('geezsms');
});

it('the GeezSMS profile is onboarded by mapping: a send reaches the mock through the egress proxy as a POST form with token/phone(digits)/msg/callback; accepted with the fixture api_log_id', function (): void {
    [$tenant] = smsScene(['sms.gateways.manage']);
    $seeded = app(FixtureGatewaySeeder::class)->seed('00000000-0000-7000-8000-0000000000aa');
    $gateway = $seeded['geezsms'];
    $config = GatewayConfig::fromStored($gateway->config);
    $credential = app(GatewayCredentials::class)->vendorCredential($gateway);
    expect($credential)->toBe(FixtureGatewaySeeder::GEEZSMS_FIXTURE_TOKEN);

    Http::withOptions(['proxy' => config('sms.egress_proxy')])->delete('http://mock-sms:8082/_recorded');
    $message = new RenderedMessage('0192a3b4-0000-7000-8000-00000000f00d', '+251900001234', 'ትኬትዎ ተፈትቷል', null, null);
    $result = (new HttpGenericDriver)->send($message, $config, $credential);

    expect($result->accepted)->toBeTrue()->and($result->providerId)->toMatch('/^\d{7}$/')->and($result->httpStatus)->toBe(200);

    $recorded = Http::withOptions(['proxy' => config('sms.egress_proxy')])->get('http://mock-sms:8082/_recorded')->json();
    $send = collect($recorded['sends'])->last();
    expect($send['profile'])->toBe('geezsms')
        ->and($send['phone'])->toBe('251900001234', '§16: digits without +')
        ->and($send['msg'])->toBe('ትኬትዎ ተፈትቷል')
        ->and((string) $send['api_log_id'])->toBe($result->providerId);
});

it('R-D14-1.5: the profile\'s `success` parses to ACCEPTED and nothing beyond; an unknown word is Unknown, never guessed', function (): void {
    $config = GatewayConfig::fromStored(FixtureGatewaySeeder::geezsmsMapping('http://mock-sms:8082/geezsms/api/v1'));
    $driver = new HttpGenericDriver;

    $parsed = $driver->parseDlr(['message_status' => 'success', 'log' => 'async x', 'phone' => '251900001234', 'message' => 'x', 'api_log_id' => 6569830], $config);
    expect($parsed->status)->toBe(DlrStatus::Accepted)->and($parsed->providerId)->toBe('6569830')->and($parsed->raw)->toBe('success')->and($parsed->status->impliesDelivery())->toBeFalse();

    $unknown = $driver->parseDlr(['message_status' => 'delivered', 'api_log_id' => 1], $config);
    expect($unknown->status)->toBe(DlrStatus::Unknown, 'a word the mapping does not name is never a delivery');
});

it('the vendor cap (§12) and the template marker (§7) are refused BEFORE a byte leaves; the credential rides the form only — a GET mapping is refused by the driver (R-D14-1.6)', function (): void {
    $config = GatewayConfig::fromStored(FixtureGatewaySeeder::geezsmsMapping('http://mock-sms:8082/geezsms/api/v1'));
    $driver = new HttpGenericDriver;
    Http::fake(); // no request may leave for these refusals

    $long = $driver->send(new RenderedMessage('m', '+251900001234', str_repeat('a', 335), null, null), $config, 'fixture-geezsms-token');
    expect($long->accepted)->toBeFalse()->and($long->code)->toBe('vendor_length_cap')->and($long->retryable)->toBeFalse();

    $marker = $driver->send(new RenderedMessage('m', '+251900001234', 'Hello [[fname]]', null, null), $config, 'fixture-geezsms-token');
    expect($marker->code)->toBe('vendor_template_marker');

    $getMapping = FixtureGatewaySeeder::geezsmsMapping('http://mock-sms:8082/geezsms/api/v1');
    $getMapping['send']['method'] = 'GET';
    $get = $driver->send(new RenderedMessage('m', '+251900001234', 'hi', null, null), GatewayConfig::fromStored($getMapping), 'fixture-geezsms-token');
    expect($get->code)->toBe('get_send_refused');

    $noCredential = $driver->send(new RenderedMessage('m', '+251900001234', 'hi', null, null), $config, null);
    expect($noCredential->code)->toBe('credential_missing')->and($noCredential->gatewayLevel)->toBeTrue();
    Http::assertNothingSent();
});

it('FR-11 classification over the wire: the REFERENCE profile — 401 auth is gateway-level non-retryable, 503 retryable, 400 per-message non-retryable, 200 accepted with the id', function (): void {
    [$tenant] = smsScene(['sms.gateways.manage']);
    $seeded = app(FixtureGatewaySeeder::class)->seed('00000000-0000-7000-8000-0000000000aa');
    $config = GatewayConfig::fromStored($seeded['reference']->config);
    $driver = new HttpGenericDriver;
    $token = FixtureGatewaySeeder::REFERENCE_FIXTURE_TOKEN;

    $ok = $driver->send(new RenderedMessage('m1', '+251900001234', 'hi', 'NABET', null), $config, $token);
    expect($ok->accepted)->toBeTrue()->and($ok->providerId)->toStartWith('ref-');

    $auth = $driver->send(new RenderedMessage('m2', '+251900000007', 'hi', null, null), $config, $token);
    expect($auth->accepted)->toBeFalse()->and($auth->code)->toBe('auth_rejected')->and($auth->retryable)->toBeFalse()->and($auth->gatewayLevel)->toBeTrue();

    $busy = $driver->send(new RenderedMessage('m3', '+251900000005', 'hi', null, null), $config, $token);
    expect($busy->retryable)->toBeTrue()->and($busy->httpStatus)->toBe(503);

    $bad = $driver->send(new RenderedMessage('m4', '+251900000006', 'hi', null, null), $config, $token);
    expect($bad->retryable)->toBeFalse()->and($bad->gatewayLevel)->toBeFalse()->and($bad->code)->toBe('rejected_400');

    $wrongToken = $driver->send(new RenderedMessage('m5', '+251900001234', 'hi', null, null), $config, 'fixture-WRONG');
    expect($wrongToken->code)->toBe('auth_rejected');
});
