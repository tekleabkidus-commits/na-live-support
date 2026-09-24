<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once dirname(__DIR__).'/Feature/Sms/PipelineSupport.php';

/*
| Doc 14 AC-07 / SS-04 — the lane-isolation drill at DEV SCALE (N stated;
| the 8,000-recipient run at scale is [D19]): transactional queued-to-sent
| latency is measured ALONE (the baseline), then WITH a broadcast's sends
| interleaved on the same gateway and bucket — the p95 delta must stay
| ≤ 5 % or the miss is FLAGGED in the assertion message. In-process serial
| interleaving exercises the shared gateway + token bucket; the
| worker-level contention story is the four-supervisor Horizon physics
| (FailureScenariosTest) — both halves stated. Run via `make sms-lane-drill`.
*/

it('AC-07 dev-scale (N=40 broadcast sends interleaved with 12 transactional): the transactional p95 delta stays ≤ 5% — or the miss is flagged', function (): void {
    $scene = smsPipeScene();
    Queue::fake();
    $nowAddis = new DateTimeImmutable('now', new DateTimeZone('Africa/Addis_Ababa'));
    app(App\Support\Settings\SettingsRepository::class)->set('sms.quiet_hours', ['start' => $nowAddis->modify('+3 hours')->format('H:i'), 'end' => $nowAddis->modify('+4 hours')->format('H:i')]);

    $sendOnce = function (string $phone, string $code): float {
        $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
            SendableEvent::VerificationCode, customerId: null, phoneE164Override: $phone, code: $code,
        )));
        $start = hrtime(true);
        $deadline = microtime(true) + 10;
        do {
            smsPipeRunJob((string) $result->smsMessageId);
            $state = (string) DB::table('sms_messages')->where('id', $result->smsMessageId)->value('state');
            if ($state === 'sent') {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
        expect($state)->toBe('sent');

        return (hrtime(true) - $start) / 1e6;
    };

    // BASELINE: 12 transactional sends alone
    $baseline = [];
    for ($i = 0; $i < 12; $i++) {
        $baseline[] = $sendOnce(sprintf('+2519000%05d', 5000 + $i), (string) (500000 + $i));
    }

    // THE DRILL: a 40-recipient broadcast's rows sending on the same gateway, interleaved
    $broadcastRows = [];
    for ($i = 0; $i < 40; $i++) {
        $customer = App\Models\Customer::query()->create(['display_name' => 'Drill '.$i, 'username' => '@fixture_drill_'.$i, 'is_shell' => true, 'created_source' => 'staff']);
        app(App\Domain\Customers\Identity\LinkIdentity::class)->link($customer->identifier(), 'phone', sprintf('+2519000%05d', 6000 + $i), $scene['user']->identifier());
        $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(SendableEvent::ServiceAnnouncement, $customer->identifier(), broadcastId: null)));
        if ($result->status === 'queued') {
            $broadcastRows[] = (string) $result->smsMessageId;
        }
    }
    $under = [];
    $b = 0;
    for ($i = 0; $i < 12; $i++) {
        for ($k = 0; $k < 3 && $b < count($broadcastRows); $k++, $b++) {
            smsPipeRunJob($broadcastRows[$b]); // three announcement sends between every transactional one
        }
        $under[] = $sendOnce(sprintf('+2519000%05d', 5100 + $i), (string) (510000 + $i));
    }

    sort($baseline);
    sort($under);
    $p95 = static fn (array $ms): float => $ms[(int) floor(count($ms) * 0.95) - 1];
    $delta = ($p95($under) - $p95($baseline)) / max($p95($baseline), 0.001);
    reportBudget(sprintf('SS-04 lane drill: transactional p95 %.1f → %.1f ms under a broadcast (Δ %.1f%%; N=40+12 dev scale, [D19] at 8,000)', $p95($baseline), $p95($under), $delta * 100), $p95($baseline) * 1.05, ['p50' => $under[(int) (count($under) / 2)], 'p99' => $p95($under), 'max' => max($under)]);
    expect($delta)->toBeLessThanOrEqual(0.05, sprintf('AC-07 MISS FLAGGED: transactional p95 rose %.1f%% under the broadcast (dev scale N=40; the figure and the miss recorded either way)', $delta * 100));
});
