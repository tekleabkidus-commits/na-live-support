<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FS-05: "Defense-in-depth breach (illegal state somehow persisted) → nightly
 * invariant auditor scans for illegal statuses / duplicate open-family and pages
 * with row ids; exists precisely because it should never fire."
 *
 * Every state it hunts is ALREADY impossible twice over — the status CHECK and the
 * T-02 partial unique index are database physics, behind the service checks. This
 * auditor is the third layer, for the day some future migration, restore, or manual
 * intervention quietly removed one of the first two. It scans exactly the two
 * invariants FS-05 names; a wider net is a different feature.
 *
 * PAGING is the PartitionsCheck idiom: the non-zero EXIT is the alert (the scheduler
 * surfaces it), and the Log::critical entry carries the ROW IDS — a page that names
 * rows is actionable at 03:00; a page that says "something is wrong" is not.
 *
 * Platform connection: the invariants are platform-wide physics, and the scan must
 * see every tenant's rows to say they hold.
 */
final class TicketInvariantAudit extends Command
{
    protected $signature = 'tickets:invariant-audit';

    protected $description = 'Nightly FS-05 auditor: illegal ticket statuses and duplicate open-family rows (Doc 06)';

    private const LEGAL_STATUSES = "('new', 'open', 'pending_customer', 'pending_internal', 'escalated', 'resolved', 'closed', 'spam')";

    public function handle(): int
    {
        /** @var array{illegal: list<object{id: string, tenant_id: string, status: string}>, duplicates: list<object{conversation_id: string, ids: string}>} $found */
        $found = PlatformConnection::run(
            PlatformActor::ReconciliationJob,
            'ticket invariant audit',
            function (): array {
                /** @var list<object{id: string, tenant_id: string, status: string}> $illegal */
                $illegal = DB::connection(PlatformConnection::NAME)->select(
                    'SELECT id, tenant_id, status FROM tickets WHERE status NOT IN '.self::LEGAL_STATUSES,
                );

                /** @var list<object{conversation_id: string, ids: string}> $duplicates */
                $duplicates = DB::connection(PlatformConnection::NAME)->select(
                    "SELECT conversation_id, string_agg(id::text, ',' ORDER BY id) AS ids
                       FROM tickets
                      WHERE status IN ('new', 'open', 'pending_customer', 'pending_internal', 'escalated')
                        AND linked_work = FALSE
                      GROUP BY conversation_id
                     HAVING count(*) > 1",
                );

                return ['illegal' => $illegal, 'duplicates' => $duplicates];
            },
        );

        if ($found['illegal'] === [] && $found['duplicates'] === []) {
            $this->info('FS-05 invariant audit: clean (no illegal statuses, no duplicate open-family rows).');

            return self::SUCCESS;
        }

        // THE PAGE, with row ids (FS-05's own words).
        Log::critical('FS-05 INVARIANT BREACH: an impossible ticket state persisted — a physics layer has been removed', [
            'illegal_status_rows' => array_map(
                static fn (object $row): array => ['id' => $row->id, 'tenant_id' => $row->tenant_id, 'status' => $row->status],
                $found['illegal'],
            ),
            'duplicate_open_family' => array_map(
                static fn (object $row): array => ['conversation_id' => $row->conversation_id, 'ticket_ids' => $row->ids],
                $found['duplicates'],
            ),
        ]);

        $this->error(sprintf(
            'FS-05 invariant audit: BREACH — %d illegal-status row(s), %d conversation(s) with duplicate open-family tickets. Row ids are in the critical log entry.',
            count($found['illegal']),
            count($found['duplicates']),
        ));

        return self::FAILURE;
    }
}
