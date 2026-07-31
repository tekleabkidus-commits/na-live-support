<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monthly partition arithmetic for the events table (Doc 01 EC-10, NFR-07).
 *
 * The naming convention events_YYYY_MM was declared load-bearing in the events
 * migration, which promised this class by name — the migration creates the first
 * window, this class maintains it forever after. Three responsibilities:
 *
 *   ensureFutureMonths   EC-10's mitigation: partitions pre-created MONTHS_AHEAD
 *                        months forward, idempotently, so month rollover needs no
 *                        just-in-time DDL on the write path.
 *   dropExpired          NFR-07: raw events keep 13 rolling months; older partitions
 *                        are detached then dropped. "Aggregated forever" is Doc 15's
 *                        half — it aggregates BEFORE the window closes, not this class.
 *   monitoring reads     futureMonthsCovered() and defaultPartitionRows() are the two
 *                        facts EC-10's monitor asserts: the future is pre-created, and
 *                        the DEFAULT partition is EMPTY. A row in events_default means
 *                        some occurred_at fell outside every real partition — the
 *                        safety net caught something, which is an alarm, not a save.
 *
 * ALL DDL RUNS ON THE MIGRATOR CONNECTION. The app role cannot CREATE or DROP (ADR
 * 0003's separation), and that is correct: partition maintenance is schema surgery,
 * and schema surgery does not belong to the role that handles player traffic.
 *
 * "Today" is a PARAMETER, never now() read internally: the callers own the clock
 * (commands pass real UTC now; tests pass fixed dates), which is what keeps the §8
 * no-clock-assertions rule honest for month arithmetic.
 */
final class EventPartitions
{
    public const MONTHS_AHEAD = 3;

    public const RETENTION_MONTHS = 13;

    private const CONNECTION = 'pgsql_migrator';

    /** The partition holding a given month, e.g. events_2026_07. */
    public function nameFor(CarbonImmutable $month): string
    {
        return sprintf('events_%s', $month->startOfMonth()->format('Y_m'));
    }

    /**
     * Every existing monthly partition name, ascending. events_default excluded —
     * it is the safety net, not part of the window.
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
            WHERE c.relname = 'events' AND c2.relname <> 'events_default'
            ORDER BY c2.relname
        ");

        return array_map(static fn (object $row): string => $row->relname, $rows);
    }

    /**
     * Create any missing partitions from this month through MONTHS_AHEAD months out.
     *
     * @return list<string> the partitions actually created (empty = nothing to do)
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
                    'CREATE TABLE %s PARTITION OF events FOR VALUES FROM (%s) TO (%s)',
                    $name,
                    sprintf("'%s'", $month->toDateTimeString()),
                    sprintf("'%s'", $month->addMonth()->toDateTimeString()),
                ));

                // The promise the RLS migration made on this class's behalf: a parent's
                // policies do not govern DIRECT queries against a partition, so every
                // new partition gets its own copy — enable, force, police — or
                // `SELECT * FROM events_2027_01` would be an unscoped read of a month
                // of events. RlsCoverageTest asserts no partition is ever bare.
                $this->protect($name);

                $created[] = $name;
            }

            $month = $month->addMonth();
        }

        return $created;
    }

    /**
     * Detach and drop partitions wholly older than RETENTION_MONTHS (NFR-07).
     *
     * Detach first, then drop: detaching is the point of no return for queries but
     * leaves the data recoverable for the instant between the two statements, and it
     * takes a weaker lock on the parent than a direct drop would.
     *
     * The deletion is logged with a metric rather than written to audit_log — that
     * table's tenant_id is non-null by design (C-02), and a partition spans every
     * tenant. The platform-scope audit shape (hash-chained, per Doc 17) is Doc 17's;
     * until it exists, the structured log is the record.
     *
     * @return list<string> the partitions dropped
     */
    public function dropExpired(CarbonImmutable $today): array
    {
        $cutoff = $today->startOfMonth()->subMonths(self::RETENTION_MONTHS);
        $dropped = [];

        foreach ($this->existing() as $name) {
            $month = $this->monthOf($name);

            if ($month === null || ! $month->lessThan($cutoff)) {
                continue;
            }

            DB::connection(self::CONNECTION)->statement(sprintf('ALTER TABLE events DETACH PARTITION %s', $name));
            DB::connection(self::CONNECTION)->statement(sprintf('DROP TABLE %s', $name));

            Log::warning('events partition dropped per NFR-07 retention', [
                'partition' => $name,
                'month' => $month->format('Y-m'),
                'retention_months' => self::RETENTION_MONTHS,
                'metric' => 'events.partition_dropped',
            ]);

            $dropped[] = $name;
        }

        return $dropped;
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
     * Rows sitting in the DEFAULT partition. Non-zero is an alarm: every one of them
     * carries an occurred_at that no real partition covered.
     *
     * Through PlatformConnection, not the migrator: the count spans every tenant, which
     * is platform_admin's one legitimate purpose (SEC-02, logged), and FORCE RLS applies
     * to the migrator as owner — its connection never sets app.tenant_id, so the policy
     * refuses the read. The same lesson resolve_tenant_key() taught in S1.
     */
    public function defaultPartitionRows(): int
    {
        return PlatformConnection::run(
            PlatformActor::RetentionJob,
            'EC-10 default-partition monitor',
            function (): int {
                /** @var list<object{n: int|string}> $rows */
                $rows = DB::connection(PlatformConnection::NAME)->select('SELECT count(*) AS n FROM events_default');

                return (int) $rows[0]->n;
            },
        );
    }

    /**
     * The same enable + force + policy the RLS migration applies — kept textually in
     * step with its protect(); RlsCoverageTest fails if either side drifts.
     */
    private function protect(string $partition): void
    {
        DB::connection(self::CONNECTION)->statement(sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $partition));
        DB::connection(self::CONNECTION)->statement(sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $partition));
        DB::connection(self::CONNECTION)->statement(sprintf(
            <<<'SQL'
                CREATE POLICY tenant_isolation ON %1$s
                    USING (tenant_id = current_setting('app.tenant_id')::uuid)
                    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
                SQL,
            $partition,
        ));
    }

    private function monthOf(string $partitionName): ?CarbonImmutable
    {
        if (preg_match('/^events_(\d{4})_(\d{2})$/', $partitionName, $m) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromDate((int) $m[1], (int) $m[2], 1, 'UTC')->startOfDay();
    }
}
