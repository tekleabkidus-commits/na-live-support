<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use App\Support\Settings\SettingsRepository;
use App\Support\Timers\DueSet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-16 half / A-65 — `sms.hold_all_sends` honored: HELD, never
| dropped. The held row carries a DueSet `sms_hold_release` candidate; the
| sweep RE-VERIFIES against the live setting inside its own tenant transaction
| — hold still on → re-scheduled; hold lifted → held→queued through the
| machine, `sms.queued` published, the job dispatched on the row's own lane.
*/

it('hold on → held row + sms.held(release_at null) + a DueSet candidate; the sweep keeps it while the hold stands and releases it when the hold lifts', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    app(SettingsRepository::class)->set('sms.hold_all_sends', true);

    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
        SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier(),
    )));

    expect($result->status)->toBe('held')->and($result->reason)->toBe('hold_all_sends');
    /** @var App\Models\SmsMessage $row */
    $row = App\Models\SmsMessage::query()->findOrFail($result->smsMessageId);
    expect($row->state)->toBe('held');

    $held = smsPipeOutbox('sms.held');
    expect($held)->toHaveCount(1);
    $payload = json_decode($held[0]->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['reason'])->toBe('hold_all_sends')->and($payload['release_at'])->toBeNull('hold-all has no computable release instant');

    $member = DueSet::member($scene['tenant']->identifier(), (string) $result->smsMessageId);
    $farFuture = (int) ((microtime(true) + 3600) * 1_000_000);
    expect(app(DueSet::class)->due('sms_hold_release', $farFuture))->toContain($member);

    // the sweep with the hold still ON: re-verified, re-scheduled, NEVER dropped
    Artisan::call('sms:release-held', ['--now' => $farFuture]);
    bindTenant($scene['tenant']);
    expect((string) DB::table('sms_messages')->where('id', $result->smsMessageId)->value('state'))->toBe('held');
    expect(app(DueSet::class)->due('sms_hold_release', $farFuture + 3600 * 2_000_000))->toContain($member); // still held → back on the candidate list
    Queue::assertNothingPushed();

    // the hold lifts: the sweep releases through the machine and dispatches on the row lane
    app(SettingsRepository::class)->set('sms.hold_all_sends', false);
    Artisan::call('sms:release-held', ['--now' => $farFuture + 3600 * 2_000_000]);
    bindTenant($scene['tenant']);
    expect((string) DB::table('sms_messages')->where('id', $result->smsMessageId)->value('state'))->toBe('queued');
    expect(smsPipeOutbox('sms.queued'))->toHaveCount(1);
    Queue::assertPushed(App\Jobs\Sms\SendSmsJob::class, fn ($job) => $job->smsMessageId === $result->smsMessageId && $job->queue === 'sms-transactional');
    expect(app(DueSet::class)->due('sms_hold_release', $farFuture + 3600 * 4_000_000))->not->toContain($member);
});

it('the live guard holds too: a hold flipped on AFTER enqueue holds at the job — held, never dropped, never sent', function (): void {
    $scene = smsPipeScene();
    Queue::fake();

    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
        SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier(),
    )));
    expect($result->status)->toBe('queued');

    app(SettingsRepository::class)->set('sms.hold_all_sends', true);
    smsPipeRunJob((string) $result->smsMessageId);

    expect((string) DB::table('sms_messages')->where('id', $result->smsMessageId)->value('state'))->toBe('held');
    expect(smsPipeOutbox('sms.held'))->toHaveCount(1);
});
