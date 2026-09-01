<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Csat\CsatSweep;
use Illuminate\Console\Command;

/**
 * Doc 12 EC-11 — the CSAT expiry sweep. One pass per invocation; the
 * scheduler provides the cadence (the T-03 sweep posture).
 */
final class CsatExpirySweep extends Command
{
    protected $signature = 'csat:expiry-sweep';

    protected $description = 'Expire unanswered CSAT surveys past their window and emit csat.expired (Doc 12 EC-11)';

    public function handle(CsatSweep $sweep): int
    {
        $this->info(sprintf('expired %d', $sweep->sweep()));

        return self::SUCCESS;
    }
}
