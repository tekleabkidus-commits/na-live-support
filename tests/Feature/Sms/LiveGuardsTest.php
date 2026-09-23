<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 EC-02 / T-06 — the guard runs AGAIN at send time on FRESH facts: what
| changed between queue and send decides, never the enqueue-time snapshot.
*/

it('EC-02: the phone identity unlinked between queue and send → skipped:no_phone at the job, with the live verdict on guard_log', function (): void {
    $scene = smsPipeScene(['sms.send', 'tickets.view', 'customers.identity.manage']);
    Queue::fake(); // the test queue is SYNC: without the fake the job runs inline at enqueue, before the unlink under test

    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
        SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier(),
    )));
    expect($result->status)->toBe('queued');

    // Doc 08's own unlink door — customer_identities has no app DELETE grant (the door is the only remover)
    $identityId = (string) DB::table('customer_identities')->where('customer_id', $scene['ticket']->customer_id)->where('type', 'phone')->value('id');
    app(App\Domain\Customers\Identity\UnlinkIdentity::class)->unlink($identityId, 'EC-02 fixture: number left with its owner', $scene['user']->identifier());

    smsPipeRunJob((string) $result->smsMessageId);

    /** @var App\Models\SmsMessage $row */
    $row = App\Models\SmsMessage::query()->findOrFail($result->smsMessageId);
    expect($row->state)->toBe('skipped')->and($row->skip_reason)->toBe('no_phone')
        ->and(array_column($row->guard_log, 'check'))->toContain('live_at_send');
    expect(smsPipeOutbox('sms.skipped'))->toHaveCount(1);
});

it('EC-02 mirror: an opt-out recorded between queue and send → skipped:opted_out — the enqueue-time green never sends stale', function (): void {
    $scene = smsPipeScene();
    Queue::fake();

    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
        SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier(),
    )));
    expect($result->status)->toBe('queued');

    DB::table('sms_optouts')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'tenant_id' => $scene['tenant']->identifier(),
        'phone' => $scene['phone'],
        'source' => 'inbound_stop',
    ]);

    smsPipeRunJob((string) $result->smsMessageId);

    expect((string) DB::table('sms_messages')->where('id', $result->smsMessageId)->value('skip_reason'))->toBe('opted_out');
});
