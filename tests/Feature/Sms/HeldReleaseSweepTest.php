<?php

declare(strict_types=1);

use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-16 / §12's spread — the mass release: N held rows (dev scale,
| N stated) release in ONE sweep with a 0–59 s JITTER on each dispatched
| job so the queue absorbs a re-opened morning inside a minute and the
| token bucket shapes the rest (the §12 "10k held ≤ 60 s" figure is [D19]
| at scale; this is its dev-shape). Held is never dropped: the still-held
| ladder re-schedules every candidate it cannot release.
*/

it('N=120 (dev scale, stated): one sweep releases them all with the jitter spread; every row queued, every job delayed 0–59 s, the delays SPREAD', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    app(SettingsRepository::class)->set('sms.hold_all_sends', true);

    for ($i = 0; $i < 120; $i++) {
        $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
            App\Domain\Sms\Catalog\SendableEvent::VerificationCode, customerId: null,
            phoneE164Override: sprintf('+2519000%05d', 3000 + $i), code: (string) (300000 + $i),
        )));
        expect($result->status)->toBe('held');
    }
    expect(DB::table('sms_messages')->where('state', 'held')->count())->toBe(120);

    app(SettingsRepository::class)->set('sms.hold_all_sends', false);
    $start = hrtime(true);
    Artisan::call('sms:release-held', ['--now' => (int) ((microtime(true) + 120) * 1_000_000)]);
    $sweepMs = (hrtime(true) - $start) / 1e6;
    bindTenant($scene['tenant']);

    expect(DB::table('sms_messages')->where('state', 'queued')->count())->toBe(120)
        ->and(DB::table('sms_messages')->where('state', 'held')->count())->toBe(0);

    $delays = [];
    Queue::assertPushed(App\Jobs\Sms\SendSmsJob::class, function ($job) use (&$delays): bool {
        $delays[] = is_int($job->delay) ? $job->delay : 0;

        return true;
    });
    expect(count($delays))->toBe(120)
        ->and(min($delays))->toBeGreaterThanOrEqual(0)
        ->and(max($delays))->toBeLessThanOrEqual(59)
        ->and(count(array_unique($delays)))->toBeGreaterThan(20, 'the jitter must SPREAD, not cluster');
    reportBudget('held-release sweep, N=120 (dev scale; the 10k ≤ 60 s figure is [D19])', 60000.0, ['p50' => $sweepMs, 'p99' => $sweepMs, 'max' => $sweepMs]);
    expect(smsPipeOutbox('sms.queued'))->toHaveCount(120);
});
