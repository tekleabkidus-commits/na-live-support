<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\OptOut\OptOutRegistry;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-14 / AC-05 / N-16 / US-01 — opt-out is ABSOLUTE: every FR-01
| event to an opted-out fixture is skipped:opted_out — security_notification
| and verification_code included ("absolute … except none"; the blocked
| exception is a DIFFERENT guard). The document's own single carve-out
| (FR-15's optout_confirmation) is proven ONCE: sent to the phone that just
| opted out, where the market knob is on, and structurally sendable nowhere
| else.
*/

it('AC-05: EVERY FR-01 event to an opted-out customer skips as opted_out — all ten, security_notification and verification_code included', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    app(OptOutRegistry::class)->addByStaff($scene['phone'], 'customer asked at the counter', $scene['user']->identifier());

    foreach (SendableEvent::cases() as $event) {
        $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
            $event,
            $event === SendableEvent::VerificationCode ? null : $scene['ticket']->customer_id,
            $event === SendableEvent::VerificationCode ? null : $scene['ticket']->identifier(),
            phoneE164Override: $event === SendableEvent::VerificationCode ? $scene['phone'] : null,
            code: $event === SendableEvent::VerificationCode ? '123456' : null,
        )));
        if ($event === SendableEvent::VerificationCode) {
            // the OTP door has no customer row, but the REGISTRY is phone-keyed: the guard still reads it
            expect($result->status)->toBe('skipped')->and($result->reason)->toBe('opted_out');

            continue;
        }
        expect($result->status)->toBe('skipped', $event->value.' must skip for an opted-out customer')
            ->and($result->reason)->toBe('opted_out', $event->value);
    }
    expect(DB::table('sms_messages')->where('skip_reason', 'opted_out')->count())->toBe(count(SendableEvent::cases()));
});

it('the single carve-out, proven once: with the market knob ON, ONE optout_confirmation goes to the phone that just opted out — and it SENDS despite the opt-out', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    app(SettingsRepository::class)->set('sms.optout.confirmation_required', true);

    app(OptOutRegistry::class)->addByStaff($scene['phone'], 'customer asked', $scene['user']->identifier());

    /** @var App\Models\SmsMessage $row */
    $row = App\Models\SmsMessage::query()->where('event', 'optout_confirmation')->firstOrFail();
    expect($row->state)->toBe('queued')->and($row->rendered_body)->not->toBeNull('rendered at insert — the OTP shape')
        ->and($row->template_version_id)->not->toBeNull();

    smsPipeRunJob($row->identifier());
    $row->refresh();
    expect($row->state)->toBe('sent', 'the carve-out: the opted-out guard cannot apply to the confirmation ONCE');

    // and never a second: an idempotent re-add produces nothing
    app(OptOutRegistry::class)->addByStaff($scene['phone'], 'again', $scene['user']->identifier());
    expect(App\Models\SmsMessage::query()->where('event', 'optout_confirmation')->count())->toBe(1);

    // structurally unsendable anywhere else: no SendableEvent case exists for it (STOP-D condition 1)
    expect(SendableEvent::tryFrom('optout_confirmation'))->toBeNull();
});

it('with the knob OFF (the default), an opt-out sends nothing at all', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    app(OptOutRegistry::class)->addByStaff($scene['phone'], 'customer asked', $scene['user']->identifier());
    expect(DB::table('sms_messages')->count())->toBe(0);
});
