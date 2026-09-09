<?php

declare(strict_types=1);

use App\Domain\Sms\Gateways\Drivers\SmppSidecarDriver;
use App\Domain\Sms\Gateways\GatewayConfig;
use App\Domain\Sms\Gateways\RenderedMessage;
use App\Domain\Sms\State\DlrStatus;
use Illuminate\Support\Facades\Http;

/*
| Doc 14 T-03 / FR-10 / FS-02 / AC-08 — the SMPP sidecar CONTRACT against the
| mock sidecar: private HTTPS with mTLS (a call WITHOUT the client certificate
| fails at the handshake — no HTTP at all), POST /send accepted, GET /health
| scraped, and RESTART TRANSPARENCY: the sidecar closes its listener, the
| driver classifies the gap as retryable, the next attempt lands. The SMPP
| bind itself is [D18].
*/

function sidecarConfig(): GatewayConfig
{
    return GatewayConfig::fromStored(['base_url' => 'https://mock-smpp-sidecar:8443', 'limits' => ['throughput_per_second' => 10], 'capabilities' => ['inbound_stop' => true, 'alphanumeric_sender' => false, 'max_throughput' => 10]]);
}

it('mTLS: a call without our client certificate never reaches HTTP; with it, /health answers bind up and /send accepts', function (): void {
    $dir = (string) config('sms.sidecar.mtls_dir');
    expect(file_exists($dir.'/client.crt'))->toBeTrue('the sidecar volume must carry the client certificate — is mock-smpp-sidecar up?');

    $bare = null;
    try {
        Http::withOptions(['verify' => $dir.'/ca.crt', 'timeout' => 5])->get('https://mock-smpp-sidecar:8443/health');
    } catch (Illuminate\Http\Client\ConnectionException $e) {
        $bare = $e;
    }
    expect($bare)->not->toBeNull('the sidecar must REQUIRE a client certificate (T-03 mTLS)');

    $driver = new SmppSidecarDriver;
    expect($driver->healthy(sidecarConfig(), null))->toBeTrue();

    $result = $driver->send(new RenderedMessage('0192a3b4-0000-7000-8000-00000000beef', '+251900001234', 'hi', 'NABET', null), sidecarConfig(), null);
    expect($result->accepted)->toBeTrue()->and($result->providerId)->toStartWith('smpp-');
});

it('restart transparency (T-03 / FS-02): the gap is retryable, not fatal; the next attempt lands; a sidecar DLR parses by the contract', function (): void {
    $driver = new SmppSidecarDriver;
    $dir = (string) config('sms.sidecar.mtls_dir');
    $client = Http::withOptions(['verify' => $dir.'/ca.crt', 'cert' => $dir.'/client.crt', 'ssl_key' => $dir.'/client.key', 'timeout' => 5]);
    $client->post('https://mock-smpp-sidecar:8443/_restart');
    usleep(300_000);

    $during = $driver->send(new RenderedMessage('m-during', '+251900001234', 'hi', null, null), sidecarConfig(), null);
    expect($during->accepted)->toBeFalse()->and($during->retryable)->toBeTrue()->and($during->gatewayLevel)->toBeTrue()->and($during->code)->toBe('sidecar_unreachable');

    $landed = false;
    for ($i = 0; $i < 20 && ! $landed; $i++) {
        usleep(250_000);
        $landed = $driver->send(new RenderedMessage('m-after', '+251900001234', 'hi', null, null), sidecarConfig(), null)->accepted;
    }
    expect($landed)->toBeTrue('the sidecar came back and the next attempt landed — a restart is invisible to the pipeline');

    $parsed = $driver->parseDlr(['message_id' => 'smpp-9', 'status' => 'delivered', 'at' => 'x'], sidecarConfig());
    expect($parsed->status)->toBe(DlrStatus::Delivered)->and($parsed->providerId)->toBe('smpp-9');
    expect($driver->parseDlr(['message_id' => 'smpp-9', 'status' => 'weird'], sidecarConfig())->status)->toBe(DlrStatus::Unknown);
});
