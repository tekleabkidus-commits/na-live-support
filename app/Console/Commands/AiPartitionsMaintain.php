<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\DecisionLog\AiDecisionPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Doc 09's partition window (the PartitionsMaintain shape, Doc 09-owned —
 * see AiDecisionPartitions' docblock for why it is not folded into the
 * Doc 01/04 command). Scheduled monthly beside partitions:maintain.
 */
final class AiPartitionsMaintain extends Command
{
    protected $signature = 'ai:partitions-maintain';

    protected $description = 'Create future monthly ai_decisions partitions (Doc 09 FR-20; SCHEMA AMENDMENT 3)';

    public function handle(AiDecisionPartitions $partitions): int
    {
        $created = $partitions->ensureFutureMonths(CarbonImmutable::now('UTC'));

        $this->info(sprintf(
            'ai:partitions-maintain: %d created (%s); %d future month(s) covered.',
            count($created),
            $created === [] ? 'none' : implode(', ', $created),
            AiDecisionPartitions::MONTHS_AHEAD,
        ));

        return self::SUCCESS;
    }
}
