<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Bulk;

use App\Jobs\Tickets\TicketBulkJob;
use App\Models\Ticket;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-25's submit door: a batch of INDIVIDUALLY validated operations, queued.
 *
 * WHAT IS CHECKED HERE AND WHAT IS DELIBERATELY NOT. Shape only: a known action, 1
 * to 200 deduplicated tickets that exist in this tenant. PERMISSIONS ARE NOT CHECKED
 * AT SUBMIT — SEC-04/EC-12 place the check per item, LIVE at execution, and a
 * submit-time gate would be exactly the stale snapshot EC-12 forbids (revoke between
 * submit and execution must fail the remaining items, so execution is where the
 * question is asked). The route's RequirePermission (Doc 07's mounting) still gates
 * who can reach the door at all.
 *
 * THE 200 CAP is FR-25's number and the DB CHECK enforces it below the app; the
 * mobile cap of 25 is §9's CLIENT rule (the bulk-jobs migration records the same
 * reading) — the server takes what the spec allows, the client offers less.
 */
final class BulkOperation
{
    public const MAX_ITEMS = 200;

    public const ACTIONS = ['assign', 'transfer', 'tag', 'priority', 'resolve', 'close', 'spam'];

    /**
     * @param  list<string>  $ticketIds
     * @param  array<string, mixed>  $params
     * @return string the bulk job id — the progress drawer's subscription key
     */
    public function submit(User $actor, string $action, array $ticketIds, array $params = []): string
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new DomainException(sprintf('unknown bulk action "%s" — FR-25 fixes seven.', $action));
        }

        $ids = array_values(array_unique($ticketIds));

        if ($ids === []) {
            throw new DomainException('a bulk operation with no tickets does nothing (FR-25).');
        }

        if (count($ids) > self::MAX_ITEMS) {
            throw new DomainException(sprintf('FR-25 caps a batch at %d tickets; this one has %d. Split the selection.', self::MAX_ITEMS, count($ids)));
        }

        $known = Ticket::query()->whereIn('id', $ids)->count();

        if ($known !== count($ids)) {
            throw new DomainException('the selection contains tickets that do not exist here (FR-25).');
        }

        $jobId = (string) Str::uuid7();

        DB::transaction(function () use ($jobId, $actor, $action, $ids, $params): void {
            DB::table('ticket_bulk_jobs')->insert([
                'id' => $jobId,
                'tenant_id' => $actor->getAttribute('tenant_id'),
                'requested_by' => $actor->identifier(),
                'action' => $action,
                'params' => json_encode($params, JSON_THROW_ON_ERROR),
                'total' => count($ids),
                // `status` deliberately unnamed: the column defaults to 'queued', and
                // bulk-state writes live in BulkJobStore (the NFR-03 constraint).
            ]);

            foreach ($ids as $position => $ticketId) {
                DB::table('ticket_bulk_job_items')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $actor->getAttribute('tenant_id'),
                    'bulk_job_id' => $jobId,
                    'ticket_id' => $ticketId,
                    'position' => $position,
                    // `status` defaults to 'pending' — same rule as the job row.
                ]);
            }
        });

        // afterCommit is the bus default (C-06): the worker can never race a batch
        // whose rows are not yet visible.
        TicketBulkJob::dispatch($jobId);

        return $jobId;
    }
}
