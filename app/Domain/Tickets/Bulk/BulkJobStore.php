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

    /**
     * C15's Stop: latch the cancel request on a batch that has not finished.
     * Returns false when the batch already completed (nothing left to stop)
     * or the latch was already set — the door turns that into its refusal.
     */
    public function requestCancel(string $jobId): bool
    {
        return DB::table('ticket_bulk_jobs')
            ->where('id', $jobId)
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('cancel_requested_at')
            ->update(['cancel_requested_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]) === 1;
    }

    /** The observed cancel: items that never ran finish failed('cancelled'). */
    public function failPending(string $jobId, string $reason): int
    {
        return DB::table('ticket_bulk_job_items')
            ->where('bulk_job_id', $jobId)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'failure_reason' => $reason,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
    }
}
