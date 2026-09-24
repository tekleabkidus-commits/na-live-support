<?php

declare(strict_types=1);

use App\Domain\Sms\Broadcasts\BroadcastWriter;
use App\Jobs\Sms\BroadcastChunkJob;
use App\Models\SmsTemplate;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 EC-07 — a STOP mid-run is honored by every LATER enqueue: the guard
| is LIVE PER RECIPIENT (never a launch-time snapshot), the opted-out
| member's row lands skipped:opted_out, and the REGISTRY's timestamp orders
| BEFORE the skipped row's own queued_at — the record shows the STOP came
| first. A re-driven chunk lands nothing twice (C-09 by state).
*/

it('EC-07: an opt-out landing after LAUNCH but before the chunk runs skips that member — timestamps ordered, everyone else sends; the re-driven chunk is idempotent', function (): void {
    $scene = smsPipeScene(['sms.send', 'tickets.view', 'sms.templates.view', 'sms.gateways.manage']);
    Illuminate\Support\Facades\Queue::fake();
    $nowAddis = new DateTimeImmutable('now', new DateTimeZone('Africa/Addis_Ababa'));
    app(App\Support\Settings\SettingsRepository::class)->set('sms.quiet_hours', ['start' => $nowAddis->modify('+3 hours')->format('H:i'), 'end' => $nowAddis->modify('+4 hours')->format('H:i')]);
    $stopper = App\Models\Customer::query()->create(['display_name' => 'Fixture Stopper', 'username' => '@fixture_stop_'.Illuminate\Support\Str::random(6), 'is_shell' => true, 'created_source' => 'staff']);
    app(App\Domain\Customers\Identity\LinkIdentity::class)->link($stopper->identifier(), 'phone', '+251900004411', $scene['user']->identifier());

    /** @var SmsTemplate $template */
    $template = SmsTemplate::query()->where('event', 'service_announcement')->where('language', 'en')->firstOrFail();
    $broadcast = app(BroadcastWriter::class)->create('EC-07 run', $template->identifier(), [], $scene['user']);
    app(BroadcastWriter::class)->launch($broadcast, $scene['user'], null);

    // the STOP lands mid-run: after launch, before the chunk walks the audience
    app(App\Domain\Sms\OptOut\OptOutRegistry::class)->addFromInbound('+251900004411', 'STOP');

    $run = fn () => (new BroadcastChunkJob($broadcast->identifier(), null))->handle(app(App\Domain\Sms\Broadcasts\AudienceQuery::class), app(BroadcastWriter::class), app(App\Domain\Sms\Pipeline\EnqueueSms::class), app(App\Support\Events\EventPublisher::class));
    $run();

    /** @var object{state: string, skip_reason: ?string, queued_at: string} $stopperRow */
    $stopperRow = DB::table('sms_messages')->where('broadcast_id', $broadcast->identifier())->where('customer_id', $stopper->identifier())->first(['state', 'skip_reason', 'queued_at']);
    expect($stopperRow->state)->toBe('skipped')->and($stopperRow->skip_reason)->toBe('opted_out');
    $optoutAt = (string) DB::table('sms_optouts')->where('phone', '+251900004411')->value('created_at');
    expect($optoutAt <= $stopperRow->queued_at)->toBeTrue('the registry timestamp orders BEFORE the skipped row — the STOP came first');
    expect(DB::table('sms_messages')->where('broadcast_id', $broadcast->identifier())->where('customer_id', $scene['ticket']->customer_id)->value('state'))->toBe('queued');

    // the re-drive: same chunk again → nothing doubles (C-09 by state)
    DB::table('sms_broadcasts')->where('id', $broadcast->identifier())->update(['state' => 'sending']);
    $run();
    expect(DB::table('sms_messages')->where('broadcast_id', $broadcast->identifier())->count())->toBe(2);
});
