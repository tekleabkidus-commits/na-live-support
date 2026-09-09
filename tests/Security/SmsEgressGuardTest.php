<?php

declare(strict_types=1);

use App\Domain\Sms\Gateways\Drivers\HttpGenericDriver;
use App\Domain\Sms\Gateways\FixtureGatewaySeeder;
use App\Domain\Sms\Gateways\GatewayConfig;
use App\Domain\Sms\Gateways\RenderedMessage;
use Illuminate\Support\Facades\Http;

/*
| Doc 14 SEC-01 / SEC-02 / ADR 0008 / STOP-C amendment 2 / sabotage (f) —
| the egress guard: (1) a non-allowlisted host is refused BY THE PROXY (the
| network topology, not a PHP guard); (2) the driver's ONLY log line never
| carries the request body, the credential, the full phone or the assembled
| callback URL (two secrets) — plant → detect → remove on the captured log
| records; (3) an empty proxy setting refuses to send at all.
*/

it('a non-allowlisted host is refused BY THE PROXY — the control is the topology (ADR 0008)', function (): void {
    $refused = null;
    try {
        $response = Http::withOptions(['proxy' => config('sms.egress_proxy'), 'timeout' => 5, 'connect_timeout' => 3])->get('http://mailpit:8025/');
        $refused = $response->status();
    } catch (Illuminate\Http\Client\ConnectionException $e) {
        $refused = 'connection';
    }
    expect($refused)->toBe(403, 'tinyproxy FilterDefaultDeny answers 403 for a host outside the filter');

    $allowed = Http::withOptions(['proxy' => config('sms.egress_proxy'), 'timeout' => 5])->get('http://mock-sms:8082/healthz');
    expect($allowed->status())->toBe(200);
});

it('plant → detect → remove: the driver logs status + class + the MASKED phone only — never the body, the token, or the callback URL', function (): void {
    $records = &capturedLogsRef();
    $token = 'fixture-geezsms-token';
    $callback = 'https://app/api/webhooks/sms/dlr/fixturekey12.'.str_repeat('a', 64).'/'.str_repeat('b', 64);
    $text = 'PLANTED-BODY ትኬትዎ ተፈትቷል 4812';
    $config = GatewayConfig::fromStored(FixtureGatewaySeeder::geezsmsMapping('http://mock-sms:8082/geezsms/api/v1'));

    // PLANT (the scanner control): a deliberate log line carrying every secret shape lands in the capture
    Illuminate\Support\Facades\Log::info('plant '.$text.' '.$callback, ['token' => $token, 'callback' => $callback, 'phone' => '+251900001234']);
    $planted = json_encode(end($records), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect($planted)->toContain('PLANTED-BODY')->toContain('webhooks/sms/dlr');

    // DETECT: the driver's own line, after a real send through the mock
    (new HttpGenericDriver)->send(new RenderedMessage('0192a3b4-0000-7000-8000-00000000c0de', '+251900001234', $text, null, $callback), $config, $token);
    $driverLines = array_values(array_filter($records, static fn (array $r): bool => $r['message'] === 'sms driver attempt'));
    expect($driverLines)->not->toBeEmpty();
    $line = json_encode($driverLines[count($driverLines) - 1], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect($line)->not->toContain('PLANTED-BODY')
        ->not->toContain($token)
        ->not->toContain('webhooks/sms/dlr')
        ->not->toContain('251900001234')
        ->toContain('+251 •• ••• 1234')
        ->toContain('"http_status":200');

    // REMOVE (the negative control of the scanner): the same scan on the planted line DOES find every shape
    expect($planted)->toContain($token)->toContain('251900001234');
});

it('an empty egress proxy refuses to send — never direct egress (ADR 0008)', function (): void {
    config(['sms.egress_proxy' => '']);
    $config = GatewayConfig::fromStored(FixtureGatewaySeeder::geezsmsMapping('http://mock-sms:8082/geezsms/api/v1'));

    expect(fn () => (new HttpGenericDriver)->send(new RenderedMessage('m', '+251900001234', 'hi', null, null), $config, 'fixture-geezsms-token'))
        ->toThrow(RuntimeException::class, 'direct egress is refused');
});
