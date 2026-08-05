<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Conversations\Retention\MessagesRetention as RetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * T-08's scheduled entry point. Platform work by nature (partitions are
 * global), so no --tenant: the service runs cross-tenant reads through
 * PlatformConnection and binds each tenant only to announce its own counts.
 * The operational runbook (frequency, hold procedures, verification) is the
 * Doc 18 handoff (EC-09's pre-agreed bucket).
 */
final class MessagesRetention extends Command
{
    protected $signature = 'messages:retention';

    protected $description = 'Run the settings-driven message retention: copy out legal holds, drop expired partitions (Doc 04 T-08/EC-09/AC-09)';

    public function handle(RetentionService $retention): int
    {
        $result = $retention->run(CarbonImmutable::now('UTC'));

        $this->info(sprintf(
            'messages:retention: %d partition(s) dropped (%s), %d row(s) held, %d removed.',
            count($result['dropped']),
            $result['dropped'] === [] ? 'none' : implode(', ', $result['dropped']),
            $result['held'],
            $result['removed'],
        ));

        return self::SUCCESS;
    }
}
