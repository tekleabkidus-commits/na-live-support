<?php

declare(strict_types=1);

use App\Console\Commands\Sms\MissingDlrRate;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-09's quiet half — the hourly missing-DLR-rate signal: a gateway
| whose sent messages go silent degrades TRANSITION-ONLY (the health event
| feeds notify.sms.gateway_health) with the metric on the alert line; a
| gateway below the MIN_SAMPLE gets no verdict (a quiet hour is not
| evidence). Rows are sent through the REAL pipeline; the clock injects
| FORWARD (sent_at is the DB's and app-DENIED — the honest way to age rows).
*/

it('over-threshold silence degrades the gateway once (transition-only, evented, metric logged); the under-sample gateway is left alone', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $records = &capturedLogsRef();

    for ($i = 0; $i < 10; $i++) {
        $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
            App\Domain\Sms\Catalog\SendableEvent::VerificationCode, customerId: null,
            phoneE164Override: sprintf('+2519000%05d', 2000 + $i), code: (string) (200000 + $i),
        )));
        $deadline = microtime(true) + 10;
        do {
            smsPipeRunJob((string) $result->smsMessageId);
            $state = (string) DB::table('sms_messages')->where('id', $result->smsMessageId)->value('state');
        } while ($state !== 'sent' && microtime(true) < $deadline && ! usleep(150_000));
        expect($state)->toBe('sent');
    }

    /** @var App\Models\SmsGateway $geez */
    $geez = App\Models\SmsGateway::query()->where('name', 'GeezSMS (FIXTURE profile)')->firstOrFail();
    expect(DB::table('sms_messages')->where('gateway_id', $geez->identifier())->where('state', 'sent')->count())->toBe(10)
        ->and($geez->health)->toBe('healthy');

    // two hours from now, every one of those rows is silent past the one-hour grace
    Artisan::call('sms:missing-dlr-rate', ['--now' => (int) ((microtime(true) + 2 * 3600) * 1_000_000)]);
    bindTenant($scene['tenant']);

    $geez->refresh();
    expect($geez->health)->toBe('degraded')->and($geez->circuit_state)->toBe('closed');
    $healthEvents = smsPipeOutbox('sms.gateway.health_changed');
    expect($healthEvents)->toHaveCount(1);
    expect(json_decode($healthEvents[0]->payload, true, flags: JSON_THROW_ON_ERROR)['new_health'])->toBe('degraded');
    $line = implode("\n", array_map(static fn (array $r): string => json_encode($r, JSON_THROW_ON_ERROR), $records));
    expect($line)->toContain('sms.dlr.missing_rate');

    // the reference gateway sent nothing: below MIN_SAMPLE, no verdict
    /** @var App\Models\SmsGateway $reference */
    $reference = App\Models\SmsGateway::query()->where('name', 'Reference aggregator (FIXTURE profile)')->firstOrFail();
    expect($reference->health)->toBe('healthy');

    // TRANSITION-ONLY: a second sweep re-emits nothing for the unchanged health
    Artisan::call('sms:missing-dlr-rate', ['--now' => (int) ((microtime(true) + 2 * 3600) * 1_000_000)]);
    bindTenant($scene['tenant']);
    expect(smsPipeOutbox('sms.gateway.health_changed'))->toHaveCount(1)
        ->and(MissingDlrRate::MIN_SAMPLE)->toBe(10)->and(MissingDlrRate::THRESHOLD)->toBe(0.5);
});
