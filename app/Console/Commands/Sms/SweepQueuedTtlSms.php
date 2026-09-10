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
 * The 24 h queued-TTL sweep (EC-12's ladder half; A-65's `sms_queued_ttl`
 * kind): a row still `queued` a full `sms.queued_ttl_hours` after it entered
 * the queue — every retry round spent, every gateway down, or no worker — is
 * failed HONESTLY as `no_route_available` rather than left pretending. The
 * same DueSet law as every sweep; `--now` for the injected-clock tests only.
 */
final class SweepQueuedTtlSms extends Command
{
    use RunsWithinTenant;

    protected $signature = 'sms:sweep-queued-ttl {--now= : microseconds; defaults to the DB clock}';

    protected $description = 'Fail SMS rows still queued past sms.queued_ttl_hours (no_route_available)';

    public function handle(DueSet $timers, SmsDeliveryStateMachine $machine): int
    {
        $nowMicros = is_numeric($this->option('now')) ? (int) $this->option('now') : (int) (microtime(true) * 1_000_000);
        $failed = 0;

        foreach ($timers->due('sms_queued_ttl', $nowMicros) as $member) {
            [$tenantId, $smsMessageId] = DueSet::parseMember($member);

            $moved = $this->withinTenant($tenantId, function () use ($smsMessageId, $machine, $nowMicros): bool {
                return DB::transaction(function () use ($smsMessageId, $machine, $nowMicros): bool {
                    $row = SmsMessage::query()->find($smsMessageId);
                    if ($row === null || $row->state !== 'queued') {
                        return false; // queued_at is NOT NULL by physics (the DB default stamps it)
                    }
                    $ttlHours = app(SettingsRepository::class)->get('sms.queued_ttl_hours');
                    $deadline = (int) $row->queued_at->format('Uu') + (is_int($ttlHours) ? $ttlHours : 24) * 3600 * 1_000_000;
                    if ($nowMicros < $deadline) {
                        return false;
                    }
                    $row->fail_reason = 'no_route_available';
                    $row->state = $machine->transition(SmsDeliveryState::Queued, SmsDeliveryState::Failed)->value;
                    $row->save();
                    app(SmsLifecycle::class)->publish('sms.failed', $row, 'no_route_available');

                    return true;
                });
            });

            $timers->cancel('sms_queued_ttl', $member);
            if ($moved) {
                $failed++;
            }
        }

        $this->info(sprintf('failed %d as no_route_available', $failed));

        return self::SUCCESS;
    }
}
