<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * T-04: "snapshots pruned after the window."
 *
 * Platform connection, because the app role has no DELETE on ticket_merge_snapshots
 * (SEC-02's immutability is a grant, and pruning is retention, not mutation — the
 * retention precedent). The cut-off is the row's own DB-stamped created_at + 24 h in
 * DB time; FS-04 already makes a pruned row refuse identically to a stale one, so the
 * prune cadence is hygiene, never correctness.
 */
final class TicketMergeSnapshotsPrune extends Command
{
    protected $signature = 'tickets:merge-snapshots-prune';

    protected $description = 'Delete unmerge snapshots past their 24 h window (Doc 06 T-04)';

    public function handle(): int
    {
        $pruned = PlatformConnection::run(
            PlatformActor::RetentionJob,
            'merge snapshot prune',
            fn (): int => DB::connection(PlatformConnection::NAME)->delete(
                "DELETE FROM ticket_merge_snapshots WHERE created_at + interval '24 hours' < clock_timestamp()",
            ),
        );

        $this->info(sprintf('pruned %d expired unmerge snapshot(s)', $pruned));

        return self::SUCCESS;
    }
}
