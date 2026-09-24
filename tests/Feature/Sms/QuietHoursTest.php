<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use App\Domain\Sms\QuietHours\QuietHoursWindow;
use App\Support\Settings\SettingsRepository;
use App\Support\Timers\DueSet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-16 / T-05 / EC-05 / FR-04-exempt — quiet hours LIVE: the window
| evaluated in the TENANT's timezone (the fixture tenants are Addis, UTC+3);
| hold vs skip per the template's own flag; verification_code and
| security_notification exempt by seed; EC-05 with the injected clock — a
| SHRUNK window releases early-but-in-window, a still-covering one
| re-schedules (never mid-night); the release-now door and the held data
| door (§8).
*/

/** Sets sms.quiet_hours so that RIGHT NOW is inside (or outside) the window, in the tenant's own tz. */
function smsQuietWindow(bool $coveringNow, int $endOffsetMinutes = 120): void
{
    $nowAddis = (new DateTimeImmutable('now', new DateTimeZone('Africa/Addis_Ababa')));
    $start = $coveringNow ? $nowAddis->modify('-120 minutes') : $nowAddis->modify('+180 minutes');
    $end = $coveringNow ? $nowAddis->modify(sprintf('+%d minutes', $endOffsetMinutes)) : $nowAddis->modify('+240 minutes');
    app(SettingsRepository::class)->set('sms.quiet_hours', ['start' => $start->format('H:i'), 'end' => $end->format('H:i')]);
}

it('inside the window a hold-policy template HOLDS with the release instant on the event; a skip-policy template SKIPS; outside the window everything proceeds', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    smsQuietWindow(coveringNow: true);

    $held = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier())));
    expect($held->status)->toBe('held')->and($held->reason)->toBe('quiet_hours');
    $payload = json_decode(smsPipeOutbox('sms.held')[0]->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['reason'])->toBe('quiet_hours')->and($payload['release_at'])->not->toBeNull('a quiet hold knows its release instant');
    expect(app(DueSet::class)->due('sms_hold_release', (int) ((microtime(true) + 3 * 3600) * 1_000_000)))->toContain(DueSet::member($scene['tenant']->identifier(), (string) $held->smsMessageId));

    // the SAME event with the template flipped to skip: skipped:quiet_hours (FR-16's per-template flag)
    DB::table('sms_templates')->where('event', 'ticket_created')->update(['quiet_hours_policy' => 'skip']);
    $skipped = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::TicketCreated, $scene['ticket']->customer_id, $scene['ticket']->identifier())));
    expect($skipped->status)->toBe('skipped')->and($skipped->reason)->toBe('quiet_hours');

    // outside the window: a fresh ticket queues
    smsQuietWindow(coveringNow: false);
    $conversation = anotherConversation($scene['tenant']->identifier(), 'qh', $scene['ticket']->customer_id);
    $ticketId = (string) Illuminate\Support\Str::uuid7();
    DB::table('tickets')->insert(['id' => $ticketId, 'tenant_id' => $scene['tenant']->identifier(), 'conversation_id' => $conversation->identifier(), 'customer_id' => $scene['ticket']->customer_id, 'number' => random_int(1, 2_000_000_000), 'status' => 'open', 'priority' => 'normal', 'source' => 'agent']);
    $clear = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::TicketResolved, $scene['ticket']->customer_id, $ticketId)));
    expect($clear->status)->toBe('queued');
});

it('FR-04: verification_code and security_notification are exempt by seed — they send INSIDE the window', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    smsQuietWindow(coveringNow: true);

    $otp = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::VerificationCode, customerId: null, phoneE164Override: '+251900008801', code: '445566')));
    expect($otp->status)->toBe('queued', 'a login code cannot wait for morning');

    app(SettingsRepository::class)->set('sms.events.security_notification.enabled', true);
    $security = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::SecurityNotification, $scene['ticket']->customer_id, $scene['ticket']->identifier())));
    expect($security->status)->toBe('queued');
});

it('the tenant timezone decides: the same wall instant is quiet in Addis and clear for a UTC tenant with the same setting', function (): void {
    $sceneAddis = smsPipeScene();
    $nowAddis = new DateTimeImmutable('now', new DateTimeZone('Africa/Addis_Ababa'));
    $window = ['start' => $nowAddis->modify('-1 hour')->format('H:i'), 'end' => $nowAddis->modify('+1 hour')->format('H:i')];
    app(SettingsRepository::class)->set('sms.quiet_hours', $window);
    expect(app(QuietHoursWindow::class)->inQuietHours(new DateTimeImmutable))->toBeTrue();

    // a UTC tenant, the SAME H:i setting: now-in-Addis is three hours away from now-in-UTC
    $utcTenant = createTenant(['timezone_name' => 'UTC']);
    activateTenant($utcTenant);
    bindTenant($utcTenant);
    app(SettingsRepository::class)->set('sms.quiet_hours', $window);
    expect(app(QuietHoursWindow::class)->inQuietHours(new DateTimeImmutable))->toBeFalse('the window is the TENANT\'s clock, not the platform\'s');
})->skip(fn (): bool => abs((int) (new DateTimeImmutable('now', new DateTimeZone('Africa/Addis_Ababa')))->format('G') - 1) < 2 || (int) (new DateTimeImmutable('now', new DateTimeZone('Africa/Addis_Ababa')))->format('G') >= 22, 'near-midnight in either zone the ±1 h windows overlap — the leg is only meaningful mid-day (skip reason stated)');

it('EC-05, injected clock: a SHRUNK window releases early-but-in-window; a still-covering window re-schedules — never mid-night', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    smsQuietWindow(coveringNow: true, endOffsetMinutes: 300); // the original window runs five hours more

    $held = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier())));
    expect($held->status)->toBe('held');
    $member = DueSet::member($scene['tenant']->identifier(), (string) $held->smsMessageId);

    // the window SHRINKS to end in 30 minutes; the sweep runs at +31 min (injected):
    // by the OLD setting still night for hours — by TODAY's setting the night is over → release
    smsQuietWindow(coveringNow: true, endOffsetMinutes: 30);
    app(DueSet::class)->schedule('sms_hold_release', $member, (int) (microtime(true) * 1_000_000)); // the candidate comes due now
    $plus31 = (int) ((microtime(true) + 31 * 60) * 1_000_000);
    Artisan::call('sms:release-held', ['--now' => $plus31]);
    bindTenant($scene['tenant']);
    expect((string) DB::table('sms_messages')->where('id', $held->smsMessageId)->value('state'))->toBe('queued', 'early-but-in-window: the shrunk setting decides at release');
    Queue::assertPushed(App\Jobs\Sms\SendSmsJob::class, 1);

    // the mirror: a second hold; the sweep fires while the window still covers → RE-SCHEDULED at the recomputed instant, nothing released
    $conversation = anotherConversation($scene['tenant']->identifier(), 'ec05', $scene['ticket']->customer_id);
    $ticketId = (string) Illuminate\Support\Str::uuid7();
    DB::table('tickets')->insert(['id' => $ticketId, 'tenant_id' => $scene['tenant']->identifier(), 'conversation_id' => $conversation->identifier(), 'customer_id' => $scene['ticket']->customer_id, 'number' => random_int(1, 2_000_000_000), 'status' => 'open', 'priority' => 'normal', 'source' => 'agent']);
    smsQuietWindow(coveringNow: true, endOffsetMinutes: 300);
    $second = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::TicketResolved, $scene['ticket']->customer_id, $ticketId)));
    expect($second->status)->toBe('held');
    $member2 = DueSet::member($scene['tenant']->identifier(), (string) $second->smsMessageId);
    app(DueSet::class)->schedule('sms_hold_release', $member2, (int) (microtime(true) * 1_000_000));
    Artisan::call('sms:release-held', ['--now' => (int) (microtime(true) * 1_000_000)]);
    bindTenant($scene['tenant']);
    expect((string) DB::table('sms_messages')->where('id', $second->smsMessageId)->value('state'))->toBe('held', 'never mid-night')
        ->and(app(DueSet::class)->due('sms_hold_release', (int) ((microtime(true) + 6 * 3600) * 1_000_000)))->toContain($member2);
    Queue::assertPushed(App\Jobs\Sms\SendSmsJob::class, 1);
});

it('the release-now door (sms.send, audited) outruns the window; the held data door lists masked rows with the hold reason', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    smsQuietWindow(coveringNow: true);
    $held = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier())));

    $list = test()->actingAs($scene['user'], 'staff')->getJson('https://localhost/admin/sms/api/held');
    bindTenant($scene['tenant']);
    expect($list->json('held.0.id'))->toBe($held->smsMessageId)
        ->and($list->json('held.0.hold_reason'))->toBe('quiet_hours')
        ->and($list->json('held.0.phone_masked'))->toContain('•••')
        ->and((string) $list->getContent())->not->toContain(substr($scene['phone'], 1, 11));

    $released = test()->actingAs($scene['user'], 'staff')->postJson('https://localhost/admin/sms/api/held/'.$held->smsMessageId.'/release');
    bindTenant($scene['tenant']);
    $released->assertOk();
    expect((string) DB::table('sms_messages')->where('id', $held->smsMessageId)->value('state'))->toBe('queued')
        ->and(DB::table('audit_log')->where('action_key', 'sms.message.released')->count())->toBe(1);
    Queue::assertPushed(App\Jobs\Sms\SendSmsJob::class, 1);

    // a viewer without sms.send is refused both doors
    $viewer = ticketActor(['tickets.view'], 'Fixture Held Viewer');
    bindTenant($scene['tenant']);
    test()->actingAs($viewer, 'staff')->getJson('https://localhost/admin/sms/api/held')->assertForbidden();
});
