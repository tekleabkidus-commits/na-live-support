<?php

declare(strict_types=1);

namespace App\Console\Commands\Sms;

use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SmsLifecycle;
use App\Domain\Sms\State\SmsDeliveryState;
use App\Domain\Sms\State\SmsDeliveryStateMachine;
use App\Jobs\Sms\SendSmsJob;
use App\Models\SmsMessage;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Timers\DueSet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The held-release sweep (A-65's first consumer; the DueSet law throughout):
 * every due `sms_hold_release` candidate is RE-VERIFIED against the row and
 * the LIVE settings inside its own tenant transaction — the ZSET never
 * decides. The ladder at each candidate: hold-all still on → re-schedule
 * (+60 s; held, never dropped) · quiet hours NOW, RECOMPUTED against the
 * setting at release (EC-05: a shrunk window releases early-but-in-window,
 * never mid-night; a grown one holds longer) → re-schedule at the newly
 * computed release instant · clear → held→queued through the machine,
 * `sms.queued` publishes, and the job dispatches on the row's own lane WITH
 * JITTER (a 0–59 s delay) so a mass release spreads inside a minute and the
 * token bucket shapes the rest (§12's 10k ≤ 60 s at dev scale). A stale
 * member drops harmlessly.
 */
final class ReleaseHeldSms extends Command
{
    use RunsWithinTenant;

    protected $signature = 'sms:release-held {--now= : microseconds; defaults to the wall clock}';

    protected $description = 'Release held SMS rows whose hold has lifted (DueSet sms_hold_release sweep)';

    public function handle(DueSet $timers, SmsDeliveryStateMachine $machine): int
    {
        $nowMicros = is_numeric($this->option('now')) ? (int) $this->option('now') : (int) (microtime(true) * 1_000_000);
        $released = 0;
        $rescheduled = 0;

        foreach ($timers->due('sms_hold_release', $nowMicros) as $member) {
            [$tenantId, $smsMessageId] = DueSet::parseMember($member);

            $outcome = $this->withinTenant($tenantId, function () use ($smsMessageId, $machine, $nowMicros): string|int {
                return DB::transaction(function () use ($smsMessageId, $machine, $nowMicros): string|int {
                    $row = SmsMessage::query()->find($smsMessageId);
                    if ($row === null || $row->state !== 'held') {
                        return 'stale'; // the DB re-check is the authority; a stale member drops
                    }
                    if ((bool) app(SettingsRepository::class)->get('sms.hold_all_sends')) {
                        return 'still_held';
                    }
                    // EC-05: quiet hours RECOMPUTED against the setting AT RELEASE
                    $at = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $nowMicros / 1_000_000)) ?: new \DateTimeImmutable;
                    $window = app(\App\Domain\Sms\QuietHours\QuietHoursWindow::class);
                    if ($window->inQuietHours($at)) {
                        return (int) $window->releaseInstant($at)->format('Uu'); // still night by TODAY's setting: the new instant
                    }
                    $row->state = $machine->transition(SmsDeliveryState::Held, SmsDeliveryState::Queued)->value;
                    $row->save();
                    app(SmsLifecycle::class)->publish('sms.queued', $row);
                    SendSmsJob::dispatch($row->identifier(), $row->lane)->delay(random_int(0, 59)); // the jitter spread

                    return 'released';
                });
            });

            if ($outcome === 'still_held' || is_int($outcome)) {
                $timers->schedule('sms_hold_release', $member, is_int($outcome) ? max($outcome, $nowMicros + 1_000_000) : $nowMicros + EnqueueSms::HOLD_RECHECK_SECONDS * 1_000_000);
                $rescheduled++;

                continue;
            }
            $timers->cancel('sms_hold_release', $member);
            if ($outcome === 'released') {
                $released++;
            }
        }

        $this->info(sprintf('released %d, still held %d', $released, $rescheduled));

        return self::SUCCESS;
    }
}
