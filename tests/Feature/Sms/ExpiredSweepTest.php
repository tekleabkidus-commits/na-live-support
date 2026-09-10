<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use App\Support\Timers\DueSet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-12 / EC-03 / EC-12 half — the two honest-terminal sweeps on
| DueSet (A-65's sms_expiry + sms_queued_ttl), injected clock: a `sent` row
| whose DLR never came reaches `expired` ("Sent · unconfirmed" — the §9
| wording, asserted) 48 h after ITS OWN sent_at; a row still `queued` a full
| 24 h after ITS OWN queued_at fails as `no_route_available`; a premature
| sweep moves NOTHING (the column re-check is the authority, never the ZSET).
*/

it('sent + 48 h → expired with the honest wording; a premature member is re-verified and refused; the terminal cancels the candidate', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
        SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier(),
    )));
    smsPipeRunJob((string) $result->smsMessageId);
    /** @var App\Models\SmsMessage $row */
    $row = App\Models\SmsMessage::query()->findOrFail($result->smsMessageId);
    expect($row->state)->toBe('sent')->and($row->sent_at)->not->toBeNull();

    $member = DueSet::member($scene['tenant']->identifier(), $row->identifier());
    $sentMicros = (int) $row->sent_at->format('Uu');
    expect(app(DueSet::class)->due('sms_expiry', $sentMicros + 49 * 3600 * 1_000_000))->toContain($member);

    // a PREMATURE candidate (planted early — a lost-and-rebuilt ZSET's worst case): the re-check refuses
    app(DueSet::class)->schedule('sms_expiry', $member, $sentMicros);
    Artisan::call('sms:sweep-expired', ['--now' => $sentMicros + 3600 * 1_000_000]);
    bindTenant($scene['tenant']);
    expect((string) DB::table('sms_messages')->where('id', $row->identifier())->value('state'))->toBe('sent', 'delayed, never premature — the column truth decides');

    // re-arm at truth (the sweep consumed the planted member), then the honest expiry
    app(DueSet::class)->schedule('sms_expiry', $member, $sentMicros + 48 * 3600 * 1_000_000);
    Artisan::call('sms:sweep-expired', ['--now' => $sentMicros + (48 * 3600 + 60) * 1_000_000]);
    bindTenant($scene['tenant']);
    $row->refresh();
    expect($row->state)->toBe('expired')->and($row->terminal_at)->not->toBeNull();
    expect(smsPipeOutbox('sms.expired'))->toHaveCount(1);
    expect((string) __('sms.chip.expired'))->toBe('Sent · unconfirmed');
    expect(app(DueSet::class)->due('sms_expiry', $sentMicros + 100 * 3600 * 1_000_000))->not->toContain($member);
});

it('queued + 24 h → failed:no_route_available (EC-12\'s ladder half); a fresh queued row is left alone', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
        SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier(),
    )));
    /** @var App\Models\SmsMessage $row */
    $row = App\Models\SmsMessage::query()->findOrFail($result->smsMessageId);
    expect($row->state)->toBe('queued');
    $member = DueSet::member($scene['tenant']->identifier(), $row->identifier());
    $queuedMicros = (int) $row->queued_at->format('Uu');

    Artisan::call('sms:sweep-queued-ttl', ['--now' => $queuedMicros + 3600 * 1_000_000]);
    bindTenant($scene['tenant']);
    expect((string) DB::table('sms_messages')->where('id', $row->identifier())->value('state'))->toBe('queued', 'an hour is not a day');

    app(DueSet::class)->schedule('sms_queued_ttl', $member, $queuedMicros + 24 * 3600 * 1_000_000);
    Artisan::call('sms:sweep-queued-ttl', ['--now' => $queuedMicros + (24 * 3600 + 60) * 1_000_000]);
    bindTenant($scene['tenant']);
    $row->refresh();
    expect($row->state)->toBe('failed')->and($row->fail_reason)->toBe('no_route_available');
    $failed = smsPipeOutbox('sms.failed');
    expect($failed)->toHaveCount(1);
    expect(json_decode($failed[0]->payload, true, flags: JSON_THROW_ON_ERROR)['reason'])->toBe('no_route_available');
});
