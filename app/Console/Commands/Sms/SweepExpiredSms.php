<?php

declare(strict_types=1);

namespace App\Console\Commands\Sms;

use App\Domain\Sms\Pipeline\SmsLifecycle;
use App\Domain\Sms\State\SmsDeliveryState;
use App\Domain\Sms\State\SmsDeliveryStateMachine;
use App\Models\SmsMessage;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Timers\DueSet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The 48 h expiry sweep (FR-12/EC-03; A-65's `sms_expiry` kind): a `sent` row
 * whose DLR never came reaches the HONEST terminal — `expired`, the chip
 * reading "Sent · unconfirmed", never an inferred delivery. The DueSet law
 * throughout: candidates only; the re-check compares the row's own `sent_at`
 * against the sweep's clock inside its tenant transaction; a stale member
 * drops harmlessly; `--now` (microseconds) exists for the injected-clock
 * tests — production leaves it unset and the DB clock decides (C-11).
 */
final class SweepExpiredSms extends Command
{
    use RunsWithinTenant;

    protected $signature = 'sms:sweep-expired {--now= : microseconds; defaults to the DB clock}';

    protected $description = 'Move DLR-less sent SMS rows past sms.expired_after_hours to the honest expired terminal';

    public function handle(DueSet $timers, SmsDeliveryStateMachine $machine): int
    {
        $nowMicros = is_numeric($this->option('now')) ? (int) $this->option('now') : $this->dbNowMicros();
        $now = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $nowMicros / 1_000_000));
        if ($now === false) {
            throw new \RuntimeException('unrepresentable clock input');
        }
        $expired = 0;

        foreach ($timers->due('sms_expiry', $nowMicros) as $member) {
            [$tenantId, $smsMessageId] = DueSet::parseMember($member);

            $moved = $this->withinTenant($tenantId, function () use ($smsMessageId, $machine, $now): bool {
                return DB::transaction(function () use ($smsMessageId, $machine, $now): bool {
                    $row = SmsMessage::query()->find($smsMessageId);
                    if ($row === null || $row->state !== 'sent' || $row->sent_at === null) {
                        return false; // the column truth is the authority; a stale member drops
                    }
                    $hours = app(SettingsRepository::class)->get('sms.expired_after_hours');
                    if (! $machine->isExpired($row->sent_at, $now, is_int($hours) ? $hours : 48)) {
                        return false;
                    }
                    $row->state = $machine->transition(SmsDeliveryState::Sent, SmsDeliveryState::Expired)->value;
                    $row->save();
                    app(SmsLifecycle::class)->publish('sms.expired', $row);

                    return true;
                });
            });

            $timers->cancel('sms_expiry', $member);
            if ($moved) {
                $expired++;
            }
        }

        $this->info(sprintf('expired %d', $expired));

        return self::SUCCESS;
    }

    private function dbNowMicros(): int
    {
        /** @var object{us: string} $row */
        $row = DB::selectOne("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS us");

        return (int) ((new \DateTimeImmutable($row->us.' UTC'))->format('Uu'));
    }
}
