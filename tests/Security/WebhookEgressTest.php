<?php

declare(strict_types=1);

use App\Domain\Automation\Webhooks\SsrfRules;
use App\Domain\Automation\Webhooks\WebhookDeliveries;
use App\Domain\Automation\Webhooks\WebhookDestinationRefused;
use App\Domain\Automation\Webhooks\WebhookDestinations;
use App\Domain\Automation\Webhooks\WebhookSigner;
use App\Http\Middleware\VerifyWebhookSecret;
use App\Support\Settings\SettingsRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 SEC-02 / AC-09's webhook half — allowlist + SSRF rules + signed
| payloads + the egress proxy, each as a mechanism with its control
|--------------------------------------------------------------------------
|
| (1) The SSRF rules at destination BIRTH: loopback, IP literals (any),
|     single-label service names, the internal-zone suffixes, http://,
|     user-info — every one refused with the stable code; a public https
|     host lands. Sabotage (f) of the plan's enumeration removes the birth
|     check and expects THIS leg red.
| (2) url immutable by grant (42501): a landed destination cannot be walked
|     onto an internal target after the fact — RIDER 5's physics.
| (3) No direct egress: an EMPTY proxy config refuses before a byte leaves
|     (the Doc 04 SEC-03 posture, the same test shape).
| (4) Signed: the three headers ride every send and verify against the
|     inbound contract's own arithmetic (the mirror); an EMPTY secret never
|     sends — abandoned as unsigned_refused.
*/

function webhookScene(): array
{
    [$tenant, , $ticket] = ticketFixture('open');
    $admin = grantAllPermissions(ticketActor([], 'Webhook Admin'));

    return [$tenant, $ticket, $admin, app(WebhookDestinations::class)];
}

it('SEC-02 (1): the SSRF rules refuse every internal-network shape at birth with the stable code; a public https host lands with its host stored', function (): void {
    [, , $admin, $destinations] = webhookScene();
    $refused = [
        'https://localhost/hook', 'https://api.localhost/hook', 'https://127.0.0.1/hook', 'https://10.0.0.5/hook',
        'https://192.168.1.9/hook', 'https://172.16.3.3/hook', 'https://169.254.169.254/latest/meta-data',
        'https://[::1]/hook', 'https://[fd00::1]/hook', 'https://8.8.8.8/hook', 'https://postgres/hook', 'https://redis:6379/hook',
        'https://minio/hook', 'https://metadata.google.internal/hook', 'https://ops.corp/hook', 'https://printer.lan/hook',
        'http://hooks.example.test/hook', 'https://user:pw@hooks.example.test/hook', 'not a url',
    ];
    foreach ($refused as $url) {
        expect(fn () => $destinations->create('bad', $url, $admin->identifier()))
            ->toThrow(WebhookDestinationRefused::class, '', "{$url} was admitted — SEC-02's SSRF rules are open");
    }
    expect(SsrfRules::CODE)->toBe('ssrf_refused');

    $id = $destinations->create('Operator CRM', 'https://hooks.example.test/na-live', $admin->identifier());
    $row = DB::table('automation_webhook_destinations')->where('id', $id)->first();
    expect($row->host)->toBe('hooks.example.test')
        ->and((bool) $row->active)->toBeTrue()
        ->and($row->auto_disabled_at)->toBeNull();
    expect((int) DB::table('audit_log')->where('action_key', 'automation.webhook_destination.changed')->count())->toBe(1);
});

it('SEC-02 (2): the landed url is IMMUTABLE by grant — an UPDATE onto an internal target dies 42501 (RIDER 5 physics)', function (): void {
    [, , $admin, $destinations] = webhookScene();
    $id = $destinations->create('CRM', 'https://hooks.example.test/x', $admin->identifier());
    try {
        DB::table('automation_webhook_destinations')->where('id', $id)->update(['url' => 'https://127.0.0.1/steal']);
        $this->fail('the url moved — SEC-02 physics gone');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('42501');
    }
});

it('SEC-02 (3): an EMPTY egress-proxy value refuses to send at all — nothing leaves, the delivery stays queued (the Doc 04 SEC-03 posture)', function (): void {
    [, , $admin, $destinations] = webhookScene();
    Queue::fake(); // the delivery job is observed, not run inline — attempts are driven by hand below
    Http::fake();
    app(SettingsRepository::class)->set('tenant.outbound_webhook_secret', 'fixture-secret-0001');
    $id = $destinations->create('CRM', 'https://hooks.example.test/x', $admin->identifier());
    $deliveryId = app(WebhookDeliveries::class)->enqueue($id, null, null, 'automation.rule.fired', ['event' => 'automation.rule.fired']);

    expect(config('automation.webhook.egress_proxy'))->toBe('http://egress-proxy:8888', 'the default must be the proxy, never direct');
    config(['automation.webhook.egress_proxy' => '']);
    expect(fn () => app(WebhookDeliveries::class)->attempt($deliveryId))->toThrow(RuntimeException::class, 'direct egress is refused');
    Http::assertNothingSent();
    expect(DB::table('automation_webhook_deliveries')->where('id', $deliveryId)->value('state'))->toBe('queued');
});

it('SEC-02 (4): every send is SIGNED with the tenant secret — the three headers verify against the inbound contract\'s arithmetic; an empty secret never sends (unsigned_refused)', function (): void {
    [, , $admin, $destinations] = webhookScene();
    Queue::fake();
    Http::fake(['hooks.example.test/*' => Http::response('', 204)]);
    $deliveries = app(WebhookDeliveries::class);
    $id = $destinations->create('CRM', 'https://hooks.example.test/signed', $admin->identifier());

    // Empty secret (the registered default): abandoned, nothing sent.
    $unsigned = $deliveries->enqueue($id, null, null, 'automation.rule.fired', ['event' => 'automation.rule.fired']);
    expect($deliveries->attempt($unsigned))->toBe('abandoned');
    expect(DB::table('automation_webhook_deliveries')->where('id', $unsigned)->value('last_error'))->toBe('unsigned_refused');
    Http::assertNothingSent();

    app(SettingsRepository::class)->set('tenant.outbound_webhook_secret', 'fixture-secret-0001');
    $signed = $deliveries->enqueue($id, null, null, 'automation.rule.fired', ['event' => 'automation.rule.fired', 'ticket' => ['id' => (string) Str::uuid7()]]);
    expect($deliveries->attempt($signed))->toBe('delivered');
    $body = (string) DB::table('automation_webhook_deliveries')->where('id', $signed)->value('payload');
    Http::assertSent(function (Request $request) use ($body): bool {
        $ts = (int) $request->header(VerifyWebhookSecret::TIMESTAMP_HEADER)[0];
        $nonce = (string) $request->header(VerifyWebhookSecret::NONCE_HEADER)[0];
        $signature = (string) $request->header(VerifyWebhookSecret::SIGNATURE_HEADER)[0];

        return $request->url() === 'https://hooks.example.test/signed'
            && $request->body() === $body
            && abs(time() - $ts) < 300
            && $nonce !== ''
            && hash_equals(WebhookSigner::signature('fixture-secret-0001', $body, $ts, $nonce), $signature);
    });
    $row = DB::table('automation_webhook_deliveries')->where('id', $signed)->first();
    expect($row->state)->toBe('delivered')
        ->and((int) $row->attempts)->toBe(1)
        ->and((int) $row->last_status_code)->toBe(204)
        ->and($row->delivered_at)->not->toBeNull();
    expect(DB::table('automation_webhook_destinations')->where('id', $id)->value('last_delivered_at'))->not->toBeNull();
});
