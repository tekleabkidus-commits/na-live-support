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
 * the live setting inside its own tenant transaction — the ZSET never decides.
 * Hold still on → re-schedule (held, never dropped). Hold lifted → the row
 * goes held→queued through the machine, `sms.queued` publishes, and the job
 * dispatches on the row's own lane. A stale member (row gone, or no longer
 * held) drops harmlessly.
 *
 * Step 5 scope: hold-all-sends only. Step 9 adds the quiet-hours release
 * instant (recomputed against the SETTING AT RELEASE — EC-05) and the spread.
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

            $outcome = $this->withinTenant($tenantId, function () use ($smsMessageId, $machine): string {
                return DB::transaction(function () use ($smsMessageId, $machine): string {
                    $row = SmsMessage::query()->find($smsMessageId);
                    if ($row === null || $row->state !== 'held') {
                        return 'stale'; // the DB re-check is the authority; a stale member drops
                    }
                    if ((bool) app(SettingsRepository::class)->get('sms.hold_all_sends')) {
                        return 'still_held';
                    }
                    $row->state = $machine->transition(SmsDeliveryState::Held, SmsDeliveryState::Queued)->value;
                    $row->save();
                    app(SmsLifecycle::class)->publish('sms.queued', $row);
                    SendSmsJob::dispatch($row->identifier(), $row->lane);

                    return 'released';
                });
            });

            if ($outcome === 'still_held') {
                $timers->schedule('sms_hold_release', $member, $nowMicros + EnqueueSms::HOLD_RECHECK_SECONDS * 1_000_000);
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
