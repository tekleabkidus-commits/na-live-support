<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Bulk;

use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of ticket_bulk_jobs / ticket_bulk_job_items state.
 *
 * This class exists because NFR-03's guards demand it: they treat any status
 * assignment in a file that also touches the Ticket model as a TICKET status write,
 * and their own docblock names the consequence as a design constraint — "that state
 * belongs in its own repository." So this store holds every bulk-table status
 * mutation and deliberately references no ticket model, table, or query; the
 * operation and the job keep their Ticket context and delegate here.
 */
final class BulkJobStore
{
    public function markRunning(string $jobId): void
    {
        DB::table('ticket_bulk_jobs')->where('id', $jobId)->update([
            'status' => 'running',
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
    }

    public function markCompleted(string $jobId): void
    {
        DB::table('ticket_bulk_jobs')->where('id', $jobId)->update([
            'status' => 'completed',
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
    }

    /** One item's outcome: 'ok' or 'failed' with its stable reason code. */
    public function finishItem(string $itemId, string $status, ?string $reason): void
    {
        DB::table('ticket_bulk_job_items')->where('id', $itemId)->update([
            'status' => $status,
            'failure_reason' => $reason,
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
    }
}
