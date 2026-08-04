<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Storage;

use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monthly partition arithmetic for the messages table (Doc 04 T-01/R-01, Doc 01
 * EC-10's conventions) — the class the messages migration promised by name, the exact
 * shape EventPartitions established.
 *
 * DELIBERATE DIFFERENCE FROM EventPartitions: there is NO fixed dropExpired here.
 * Events expire on Doc 01 NFR-07's 13-month law; messages expire on the
 * `retention.messages.months` SETTING with per-conversation LEGAL HOLD and pre-drop
 * copy-out (T-08/EC-09/AC-09) — that job arrives in step 9 and will call
 * partition-drop primitives with the hold logic wrapped around them. A blind monthly
 * drop here would be the retention defect AC-09 exists to prevent.
 *
 * All DDL on the migrator connection (ADR 0003); "today" is a parameter, never an
 * internal now() (the §8 no-clock rule).
 *
 * FOR THE STEP-9 RETENTION JOB (established experimentally, exercised by
 * MessagePartitionTest): a monthly partition cannot be DROPped while attached — the
 * message_revisions FK clones a dependency onto every partition — and DETACH runs an
 * FK-verification SELECT on message_revisions that is itself policed by FORCE RLS:
 * an unbound migrator session errors on current_setting('app.tenant_id'), and a
 * bound one sees ONE tenant's rows, so the verification cannot catch another
 * tenant's revision references. The job must purge message_revisions for the
 * partition's month across ALL tenants (platform connection) BEFORE detaching, then
 * detach, then drop — never trusting the detach-time check.
 */
final class MessagePartitions
{
    public const MONTHS_AHEAD = 3;

    private const CONNECTION = 'pgsql_migrator';

    /** The partition holding a given month, e.g. messages_2026_08. */
    public function nameFor(CarbonImmutable $month): string
    {
        return sprintf('messages_%s', $month->startOfMonth()->format('Y_m'));
    }

    /**
     * Every existing monthly partition name, ascending; messages_default excluded —
     * the safety net is not part of the window.
     *
     * @return list<string>
     */
    public function existing(): array
    {
        /** @var list<object{relname: string}> $rows */
        $rows = DB::connection(self::CONNECTION)->select("
            SELECT c2.relname
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhparent
            JOIN pg_class c2 ON c2.oid = i.inhrelid
            WHERE c.relname = 'messages' AND c2.relname <> 'messages_default'
            ORDER BY c2.relname
        ");

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }

    /**
     * Create any missing partitions from this month through MONTHS_AHEAD out —
     * idempotent, so a missed run heals on the next (Doc 01 EC-10).
     *
     * @return list<string> the partitions actually created
     */
    public function ensureFutureMonths(CarbonImmutable $today): array
    {
        $existing = $this->existing();
        $created = [];

        $month = $today->startOfMonth();
        for ($i = 0; $i <= self::MONTHS_AHEAD; $i++) {
            $name = $this->nameFor($month);

            if (! in_array($name, $existing, true)) {
                DB::connection(self::CONNECTION)->statement(sprintf(
                    'CREATE TABLE %s PARTITION OF messages FOR VALUES FROM (%s) TO (%s)',
                    $name,
                    sprintf("'%s'", $month->toDateTimeString()),
                    sprintf("'%s'", $month->addMonth()->toDateTimeString()),
                ));

                // Per-partition RLS or the month is a direct-query hole in NFR-01 —
                // the events lesson, repeated on purpose. RlsCoverageTest's messages
                // half asserts no partition is ever bare.
                $this->protect($name);

                $created[] = $name;
            }

            $month = $month->addMonth();
        }

        return $created;
    }

    /** How many FULL months beyond today's are covered by existing partitions. */
    public function futureMonthsCovered(CarbonImmutable $today): int
    {
        $existing = $this->existing();
        $covered = 0;

        $month = $today->startOfMonth()->addMonth();
        while (in_array($this->nameFor($month), $existing, true)) {
            $covered++;
            $month = $month->addMonth();
        }

        return $covered;
    }

    /**
     * Rows in the DEFAULT partition. Non-zero is FS-04's page-the-on-call alarm:
     * a message landed outside every real partition. The safety net catching
     * something is an incident, not a save.
     */
    public function defaultPartitionRows(): int
    {
        return PlatformConnection::run(
            PlatformActor::RetentionJob,
            'Doc 04 FS-04 default-partition monitor',
            function (): int {
                /** @var list<object{n: int|string}> $rows */
                $rows = DB::connection(PlatformConnection::NAME)->select('SELECT count(*) AS n FROM messages_default');

                return (int) $rows[0]->n;
            },
        );
    }

    private function protect(string $partition): void
    {
        $connection = DB::connection(self::CONNECTION);

        $connection->statement("ALTER TABLE {$partition} ENABLE ROW LEVEL SECURITY");
        $connection->statement("ALTER TABLE {$partition} FORCE ROW LEVEL SECURITY");
        $connection->statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$partition}
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
        $connection->statement("REVOKE DELETE ON {$partition} FROM app");
    }
}
