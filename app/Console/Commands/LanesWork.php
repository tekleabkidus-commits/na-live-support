<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantOption;
use App\Domain\Conversations\Outbound\LaneWorker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * FR-15/T-02's lane worker process (ADR 0008's infra note: a worker-container
 * process per ADR 0007's precedent, NOT a Horizon queue family — lanes are Redis
 * lists consumed round-robin, and Horizon's per-job model cannot express "one
 * attempt per lane per cycle").
 *
 * Tenant-scoped by T-05's console rule: one tenant per process, --tenant
 * mandatory, deliberately no all-tenants mode — the supervisor runs one
 * `lanes:work --tenant=<id>` per active tenant (Doc 18 owns that wiring; today's
 * single-tenant dev stack runs one). Cross-tenant lane work would need
 * PlatformConnection and would put every tenant's ordering in one blast radius.
 *
 * `--once` runs a single cycle and exits — the test/drill mode, and the honest
 * building block: the loop is just cycles.
 */
final class LanesWork extends Command
{
    use ResolvesTenantOption;

    /** Idle pause between cycles; short enough that NFR-02's 2 s p95 holds. */
    private const CYCLE_SLEEP_MICROSECONDS = 200_000;

    protected $signature = 'lanes:work {--tenant= : The tenant whose lanes to consume} {--once : Run one cycle and exit}';

    protected $description = 'Consume per-conversation outbound FIFO lanes for one tenant (Doc 04 FR-15, T-02)';

    public function handle(LaneWorker $worker): int
    {
        $this->bindTenantFromOption();

        do {
            $attempts = $worker->processOnce(CarbonImmutable::now('UTC'));

            if (! $this->option('once') && $attempts === 0) {
                usleep(self::CYCLE_SLEEP_MICROSECONDS);
            }
        } while (! $this->option('once'));

        $this->info(sprintf('lanes:work: cycle complete (%d send attempt(s)).', $attempts));

        return self::SUCCESS;
    }
}
