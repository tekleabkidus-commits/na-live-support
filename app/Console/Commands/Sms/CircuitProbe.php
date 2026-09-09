<?php

declare(strict_types=1);

namespace App\Console\Commands\Sms;

use App\Domain\Sms\Failover\CircuitBreaker;
use App\Models\Tenant;
use App\Support\Tenancy\RunsWithinTenant;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Doc 14 FR-09 — `sms:circuit-probe`: every minute, every OPEN circuit past
 * its cooldown becomes half_open and is probed (the driver's health endpoint;
 * or the next real send when none is configured). A DEADLINE sweep with a
 * DB-time `now`, not a poll (the TicketTimers posture): nothing closes on a
 * counter's say-so.
 */
final class CircuitProbe extends Command
{
    use RunsWithinTenant;

    protected $signature = 'sms:circuit-probe';

    protected $description = 'Doc 14 FR-09: move open SMS gateway circuits past their cooldown to half_open and probe them';

    public function handle(CircuitBreaker $breaker): int
    {
        /** @var object{now: string} $row */
        $row = DB::selectOne('SELECT clock_timestamp() AS now');
        $now = new DateTimeImmutable($row->now);
        $totals = ['half_opened' => 0, 'closed' => 0, 'reopened' => 0];
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            if (! is_string($tenantId)) {
                continue;
            }
            $counts = $this->withinTenant($tenantId, static fn (): array => $breaker->probe($now));
            foreach ($counts as $k => $v) {
                $totals[$k] += $v;
            }
        }
        $this->info(sprintf('sms circuits: %d half-opened, %d closed, %d re-opened', $totals['half_opened'], $totals['closed'], $totals['reopened']));

        return self::SUCCESS;
    }
}
