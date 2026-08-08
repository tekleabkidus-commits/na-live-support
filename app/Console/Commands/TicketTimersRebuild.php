<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tickets\Timers\TicketTimers;
use Illuminate\Console\Command;

/**
 * FS-01's recovery: "Timer ZSET lost → rebuild from columns (truth); event-driven
 * paths keep working during rebuild; worst case auto-closes delayed, never premature."
 *
 * Run after a durable-Redis loss. Purely additive — it restores candidates at their
 * exact column deadlines; the sweep's in-transaction re-check remains the only thing
 * that ever closes a ticket, so a rebuild cannot close anything early.
 */
final class TicketTimersRebuild extends Command
{
    protected $signature = 'tickets:timers-rebuild';

    protected $description = 'Rebuild the ticket_timers ZSET from column truth (Doc 06 FS-01)';

    public function handle(TicketTimers $timers): int
    {
        $restored = $timers->rebuild();

        $this->info(sprintf('restored %d armed timer(s) from column truth', $restored));

        return self::SUCCESS;
    }
}
