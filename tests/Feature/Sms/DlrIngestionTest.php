<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Dlr\DlrApplier;
use App\Domain\Sms\Gateways\GatewayCredentials;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use App\Models\SmsGateway;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-13 / NFR-03 / EC-03 / EC-04 / T-08's matrix DRIVEN OVER THE WIRE
| (P-DLR-AUTH landed) — the GeezSMS five-field unsigned callback through the
| REAL url_secret route (202, the queued pipeline applies it), then the
| precedence matrix against the applier: `success` → ACCEPTED and nothing
| beyond (R-D14-1.5); delivered applies once and duplicates are idempotent;
| a late `sent` after delivered is ignored by annotation; EC-04's
| contradiction is RETAINED + FLAGGED, never overwritten; an unknown
| provider id is logged, dropped, counted. The ≤ 1 s figure and a dev-scale
| burst (N stated) are timed and labelled.
*/

/** @return array{row: SmsMessage, gateway: SmsGateway, scene: array<string, mixed>} */
function dlrScene(): array
{
    $scene = smsPipeScene();
    Queue::fake();
    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
        SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier(),
    )));
    smsPipeRunJob((string) $result->smsMessageId);
    /** @var SmsMessage $row */
    $row = SmsMessage::query()->findOrFail($result->smsMessageId);
    expect($row->state)->toBe('sent');
    /** @var SmsGateway $gateway */
    $gateway = SmsGateway::query()->findOrFail($row->gateway_id);

    return ['row' => $row, 'gateway' => $gateway, 'scene' => $scene];
}

/** @param array<string, mixed> $overrides */
function geezCallback(SmsMessage $row, array $overrides = []): array
{
    return $overrides + [
        'message_status' => 'success',
        'log' => 'async '.$row->identifier(),
        'phone' => substr($row->phone, 1),
        'message' => (string) $row->rendered_body,
        'api_log_id' => (int) $row->provider_id,
    ];
}

it('the WIRE pass: the vendor-shaped callback hits the real url_secret route, ACKs 202, and the queued pipeline lands the receipt — state visible ≤ 1 s (dev-scale, labelled)', function (): void {
    $context = dlrScene();
    $row = $context['row'];

    $url = app(GatewayCredentials::class)->callbackUrl($context['gateway'], false);
    expect($url)->not->toBeNull();
    $path = str_replace((string) config('sms.dlr_callback_base'), 'https://localhost', (string) $url);

    $start = hrtime(true);
    $response = test()->call('POST', $path, geezCallback($row), [], [], ['HTTP_ACCEPT' => 'application/json']);
    $response->assertStatus(202);
    bindTenant($context['scene']['tenant']);

    Queue::assertPushed(App\Jobs\Sms\ProcessDlrJob::class, function ($job) use ($context, $row): bool {
        (new App\Jobs\Sms\ProcessDlrJob($job->gatewayId, $job->payload))->handle(app(DlrApplier::class));

        return $job->gatewayId === $context['gateway']->identifier() && ($job->payload['api_log_id'] ?? null) === (int) $row->provider_id;
    });
    $elapsedMs = (hrtime(true) - $start) / 1e6;

    $row->refresh();
    expect($row->state)->toBe('sent', 'R-D14-1.5: `success` is an ACCEPTANCE — never a delivery')
        ->and($row->raw_dlrs)->toHaveCount(1)
        ->and($row->raw_dlrs[0]['raw'])->toBe('success')
        ->and($row->raw_dlrs[0]['annotation'])->toBe('submission_receipt');
    reportBudget('NFR-03 DLR wire-to-state (≤ 1 s; n=1 in-shape)', 1000.0, ['p50' => $elapsedMs, 'p99' => $elapsedMs, 'max' => $elapsedMs]);
    expect($elapsedMs)->toBeLessThan(1000.0);
});

it('T-08 driven: delivered applies once (event + stamp + audit), a duplicate is idempotent, a late `sent` is ignored, EC-04\'s contradiction is retained + flagged', function (): void {
    $context = dlrScene();
    $row = $context['row'];
    $gateway = $context['gateway'];
    $applier = app(DlrApplier::class);

    // the GeezSMS mapping knows only `success`; drive the matrix through the reference profile's vocabulary on the same applier
    $reference = SmsGateway::query()->where('name', 'Reference aggregator (FIXTURE profile)')->firstOrFail();
    DB::table('sms_messages')->where('id', $row->identifier())->update(['gateway_id' => $reference->identifier()]);
    $row->refresh();

    expect($applier->apply($reference, ['status' => 'delivered', 'message_id' => $row->provider_id]))->toBe('applied');
    $row->refresh();
    expect($row->state)->toBe('delivered')->and($row->delivered_at)->not->toBeNull();
    expect(smsPipeOutbox('sms.delivered'))->toHaveCount(1)
        ->and(DB::table('audit_log')->where('action_key', 'sms.dlr.applied')->count())->toBe(1);

    expect($applier->apply($reference, ['status' => 'delivered', 'message_id' => $row->provider_id]))->toBe('duplicate');
    expect(smsPipeOutbox('sms.delivered'))->toHaveCount(1);

    expect($applier->apply($reference, ['status' => 'sent', 'message_id' => $row->provider_id]))->toBe('late_sent_ignored');
    $row->refresh();
    expect($row->state)->toBe('delivered');

    expect($applier->apply($reference, ['status' => 'undelivered', 'message_id' => $row->provider_id]))->toBe('contradiction_undelivered_after_delivered');
    $row->refresh();
    expect($row->state)->toBe('delivered', 'precedence holds — the contradiction never overwrites')
        ->and($row->raw_dlrs)->toHaveCount(4)
        ->and(DB::table('audit_log')->where('action_key', 'sms.dlr.contradiction')->count())->toBe(1);
});

it('EC-03: an unknown provider id is logged, dropped, counted — and the GeezSMS §4 fallback (phone + message, in memory) still finds its row when the id is absent', function (): void {
    $context = dlrScene();
    $row = $context['row'];
    $gateway = $context['gateway'];
    $records = &capturedLogsRef();
    $applier = app(DlrApplier::class);

    expect($applier->apply($gateway, geezCallback($row, ['api_log_id' => 9999999])))->toBe('unknown_provider_id');
    expect(DB::table('audit_log')->where('action_key', 'sms.dlr.unknown_provider_id')->count())->toBe(1)
        ->and(json_encode(end($records), JSON_THROW_ON_ERROR))->toContain('sms.dlr.unknown_provider_id');
    $row->refresh();
    expect($row->raw_dlrs)->toHaveCount(0, 'a receipt for nobody lands on nobody');

    $fallback = geezCallback($row);
    unset($fallback['api_log_id']);
    expect($applier->apply($gateway, $fallback))->toBe('submission_receipt');
    $row->refresh();
    expect($row->raw_dlrs)->toHaveCount(1);
});

it('a dev-scale burst (N=30, stated) applies cleanly — every receipt retained, the terminal exactly one transition', function (): void {
    $context = dlrScene();
    $row = $context['row'];
    $reference = SmsGateway::query()->where('name', 'Reference aggregator (FIXTURE profile)')->firstOrFail();
    DB::table('sms_messages')->where('id', $row->identifier())->update(['gateway_id' => $reference->identifier()]);
    $row->refresh();
    $applier = app(DlrApplier::class);

    $start = hrtime(true);
    for ($i = 0; $i < 30; $i++) {
        $applier->apply($reference, ['status' => 'delivered', 'message_id' => $row->provider_id]);
    }
    $elapsedMs = (hrtime(true) - $start) / 1e6;
    reportBudget('DLR burst N=30 same-row (dev-scale; §12\'s 200/s is [D19])', 1000.0, ['p50' => $elapsedMs / 30, 'p99' => $elapsedMs / 30, 'max' => $elapsedMs]);

    $row->refresh();
    expect($row->raw_dlrs)->toHaveCount(30)
        ->and($row->state)->toBe('delivered')
        ->and(smsPipeOutbox('sms.delivered'))->toHaveCount(1, 'one transition, twenty-nine idempotent duplicates');
});
