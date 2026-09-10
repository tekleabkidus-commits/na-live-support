<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-02 / FR-11 / T-04 / NFR-01 / FR-19 half — event → queued → sent
| against the FIXTURE gateway: the row, its lifecycle events through the
| outbox, the published template's version recorded, the provider id from the
| mock, the cost accrued AT SENT from the gateway's rate card, the lane queue.
| NFR-01 measured IN-SHAPE at dev scale (event-to-queued ≤ 2 s p95,
| queued-to-sent ≤ 5 s p95) — hand-rolled small-n timings, figures labelled.
*/

it('event → queued → sent: the row walks the machine against the real mock, records version + provider id, accrues cost at sent, and publishes sms.queued + sms.sent through the outbox', function (): void {
    $scene = smsPipeScene();
    Queue::fake();

    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
        SendableEvent::TicketResolved,
        $scene['ticket']->customer_id,
        $scene['ticket']->identifier(),
        $scene['conversation']->identifier(),
    )));

    expect($result->status)->toBe('queued');
    Queue::assertPushed(App\Jobs\Sms\SendSmsJob::class, fn ($job) => $job->smsMessageId === $result->smsMessageId && $job->queue === 'sms-transactional');

    /** @var App\Models\SmsMessage $row */
    $row = App\Models\SmsMessage::query()->findOrFail($result->smsMessageId);
    expect($row->state)->toBe('queued')->and($row->lane)->toBe('sms-transactional')->and($row->rendered_body)->toBeNull();
    expect(smsPipeOutbox('sms.queued'))->toHaveCount(1);

    smsPipeRunJob($result->smsMessageId);
    $row->refresh();
    expect($row->state)->toBe('sent')
        ->and($row->provider_id)->toMatch('/^\d{7}$/')
        ->and($row->template_version_id)->not->toBeNull()
        ->and($row->rendered_body)->not->toBeNull()->not->toContain('{{')
        ->and($row->encoding)->toBeIn(['gsm7', 'ucs2'])
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->gateway_id)->not->toBeNull()
        ->and($row->cost_amount)->not->toBeNull()
        ->and($row->cost_currency)->toBe('ETB');

    $sent = smsPipeOutbox('sms.sent');
    expect($sent)->toHaveCount(1);
    $payload = json_decode($sent[0]->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['sms_message_id'])->toBe($result->smsMessageId)
        ->and($payload)->not->toHaveKey('phone')
        ->and($sent[0]->payload)->not->toContain($scene['phone']);
});

it('a skipped guard leaves an honest row: an opted-out customer enqueues as skipped{opted_out} with the ordered guard_log and sms.skipped', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    DB::table('sms_optouts')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'tenant_id' => $scene['tenant']->identifier(),
        'phone' => $scene['phone'],
        'source' => 'inbound_stop',
    ]);

    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
        SendableEvent::TicketResolved,
        $scene['ticket']->customer_id,
        $scene['ticket']->identifier(),
    )));

    expect($result->status)->toBe('skipped')->and($result->reason)->toBe('opted_out');
    /** @var App\Models\SmsMessage $row */
    $row = App\Models\SmsMessage::query()->findOrFail($result->smsMessageId);
    expect($row->state)->toBe('skipped')->and($row->skip_reason)->toBe('opted_out')
        ->and($row->terminal_at)->not->toBeNull()
        ->and(array_column($row->guard_log, 'check'))->toContain('opted_out');
    expect(smsPipeOutbox('sms.skipped'))->toHaveCount(1);
});

it('NFR-01 in-shape (dev scale, hand-rolled n, figures labelled): event-to-queued ≤ 2 s p95; queued-to-sent ≤ 5 s p95 including shaping waits', function (): void {
    smsPipeScene();
    Queue::fake(); // the sync test queue would run the job inside the enqueue timing otherwise
    $enqueueMs = [];
    $sendMs = [];

    for ($i = 0; $i < 6; $i++) {
        $phone = sprintf('+2519000%05d', 1000 + $i); // unique fixture phones: the duplicate window never trips
        $start = hrtime(true);
        $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
            SendableEvent::VerificationCode, customerId: null, phoneE164Override: $phone, code: (string) (100000 + $i),
        )));
        $enqueueMs[] = (hrtime(true) - $start) / 1e6;
        expect($result->status)->toBe('queued');

        $start = hrtime(true);
        $deadline = microtime(true) + 10;
        do {
            smsPipeRunJob((string) $result->smsMessageId);
            $state = (string) DB::table('sms_messages')->where('id', $result->smsMessageId)->value('state');
            if ($state === 'sent') {
                break;
            }
            usleep(200_000); // a shaping WAIT released the job; the worker's redelivery, hand-rolled
        } while (microtime(true) < $deadline);
        $sendMs[] = (hrtime(true) - $start) / 1e6;
        expect($state)->toBe('sent');
    }

    sort($enqueueMs);
    sort($sendMs);
    $stats = static fn (array $ms): array => ['p50' => $ms[(int) floor(count($ms) / 2)], 'p99' => $ms[count($ms) - 1], 'max' => $ms[count($ms) - 1]];
    reportBudget('NFR-01 event-to-queued (≤ 2 s p95; n=6 in-shape)', 2000.0, $stats($enqueueMs));
    reportBudget('NFR-01 queued-to-sent (≤ 5 s p95; n=6 in-shape, shaping waits included)', 5000.0, $stats($sendMs));
    expect(max($enqueueMs))->toBeLessThan(2000.0, 'event-to-queued blew the NFR-01 figure at dev scale')
        ->and(max($sendMs))->toBeLessThan(5000.0, 'queued-to-sent blew the NFR-01 figure at dev scale');
});
