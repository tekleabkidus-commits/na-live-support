<?php

declare(strict_types=1);

use App\Domain\Sms\Broadcasts\BroadcastQuotaGate;
use App\Domain\Sms\Broadcasts\BroadcastWriter;
use App\Jobs\Sms\BroadcastChunkJob;
use App\Models\SmsTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 EC-11 — the quota-pause STATE MACHINE, proven through the
| BroadcastQuotaGate seam (the real monthly-segment meter is P-QUOTA-SMS, at
| step 11): a chunk that hits the wall parks the broadcast at `paused_quota`
| and sends nothing more; when headroom returns, a later chunk resumes and
| completes. The seam keeps EC-11 provable at step 10 without the not-yet-
| existing Quota case (stated in the step-10 record).
*/

it('a chunk at the quota wall pauses the broadcast (paused_quota, nothing sent); headroom returning resumes it to done', function (): void {
    $scene = smsPipeScene(['sms.send', 'tickets.view', 'sms.templates.view', 'sms.gateways.manage']);
    Queue::fake();
    for ($i = 0; $i < 3; $i++) {
        $customer = App\Models\Customer::query()->create(['display_name' => 'Q '.$i, 'username' => '@fixture_q_'.Str::random(6), 'is_shell' => true, 'created_source' => 'staff']);
        app(App\Domain\Customers\Identity\LinkIdentity::class)->link($customer->identifier(), 'phone', sprintf('+2519000%05d', 7000 + $i), $scene['user']->identifier());
    }

    /** @var SmsTemplate $template */
    $template = SmsTemplate::query()->where('event', 'service_announcement')->where('language', 'en')->firstOrFail();
    $broadcast = app(BroadcastWriter::class)->create('Quota run', $template->identifier(), [], $scene['user']);
    app(BroadcastWriter::class)->launch($broadcast, $scene['user'], null);

    // the wall REFUSES: bind a gate that says no
    app()->bind(BroadcastQuotaGate::class, fn () => new class extends BroadcastQuotaGate
    {
        public function allows(): bool
        {
            return false;
        }
    });
    $run = fn () => (new BroadcastChunkJob($broadcast->identifier(), null))->handle(app(App\Domain\Sms\Broadcasts\AudienceQuery::class), app(BroadcastWriter::class), app(App\Domain\Sms\Pipeline\EnqueueSms::class), app(App\Support\Events\EventPublisher::class));
    $run();

    expect((string) DB::table('sms_broadcasts')->where('id', $broadcast->identifier())->value('state'))->toBe('paused_quota')
        ->and(DB::table('sms_messages')->where('broadcast_id', $broadcast->identifier())->count())->toBe(0, 'nothing sends at the wall');

    // headroom RETURNS: the default gate allows; a resuming chunk (the scheduler re-dispatches a paused broadcast — dispatched here by hand) completes it
    app()->forgetInstance(BroadcastQuotaGate::class);
    app()->bind(BroadcastQuotaGate::class, fn () => new BroadcastQuotaGate);
    DB::table('sms_broadcasts')->where('id', $broadcast->identifier())->update(['state' => 'sending']); // the resume transition (paused_quota → sending)
    $run();

    expect((string) DB::table('sms_broadcasts')->where('id', $broadcast->identifier())->value('state'))->toBe('done')
        ->and(DB::table('sms_messages')->where('broadcast_id', $broadcast->identifier())->count())->toBe(4);
});
