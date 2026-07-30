<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The event log for reporting replay (Doc 01 FR-08, T-06, NFR-07, EC-10).
 *
 * Monthly RANGE partitions on `occurred_at`. Partitioning earns its complexity
 * here for one reason: NFR-07 retains raw events for 13 rolling months, and
 * expiring a month is then `DROP TABLE events_2026_07` — instant, no bloat, no
 * vacuum storm. `DELETE FROM events WHERE occurred_at < …` on a table taking
 * 100 events/s (§12) would be a recurring incident instead of a no-op.
 *
 * TWO PARTITIONING CONSEQUENCES, BOTH DELIBERATE:
 *
 *  1. The primary key is (id, occurred_at), not (id). Postgres requires every
 *     unique constraint on a partitioned table to include the partition key.
 *     `id` is still a globally unique UUIDv7 and still the identity of the row —
 *     the composite is a storage requirement, not a modelling statement. C-01 is
 *     satisfied: the key is a UUIDv7.
 *  2. R-05 applies: partitioned tables are reached through a repository, not
 *     ad-hoc Eloquent, so nothing accidentally queries all 13 months.
 *
 * THE DEFAULT PARTITION IS A SAFETY NET, NOT A PLAN. If month-rollover
 * maintenance ever fails (EC-10), an insert with no matching partition would
 * ERROR and an event would be lost at publish time. With a DEFAULT partition the
 * event lands safely instead — and the monitor asserts that partition is EMPTY,
 * so "we survived" never gets mistaken for "we are fine". Both halves are
 * tests/Feature/Foundation/PartitionMonitorTest.php.
 *
 * Pre-created three months ahead, per EC-10, by this migration and thereafter by
 * the scheduled maintenance command.
 */
return new class extends Migration
{
    /** Months of future partitions to pre-create (EC-10). */
    private const MONTHS_AHEAD = 3;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE events (
                id              uuid        NOT NULL,
                tenant_id       uuid        NOT NULL,
                name            text        NOT NULL,
                schema_version  smallint    NOT NULL DEFAULT 1,
                payload         jsonb       NOT NULL,
                correlation_id  uuid,
                causation_id    uuid,
                occurred_at     timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        DB::statement('ALTER TABLE events ADD CONSTRAINT events_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES tenants (id)');

        // `events` is a HOT table in ci/migration-safety.php: indexes are raw DDL.
        // Declared on the partitioned parent, Postgres creates a matching index
        // on every partition, existing and future.
        DB::statement('CREATE INDEX events_tenant_occurred_index ON events (tenant_id, occurred_at DESC)');
        DB::statement('CREATE INDEX events_name_index ON events (tenant_id, name)');
        DB::statement('CREATE INDEX events_correlation_id_index ON events (correlation_id)');

        // The current month, one month back (events may be published with a
        // slightly earlier occurred_at than "now" after a queue backlog), and
        // MONTHS_AHEAD forward.
        $month = Carbon::now('UTC')->startOfMonth()->subMonth();
        for ($i = 0; $i <= self::MONTHS_AHEAD + 1; $i++) {
            $this->createMonthlyPartition($month);
            $month = $month->copy()->addMonth();
        }

        DB::statement('CREATE TABLE events_default PARTITION OF events DEFAULT');
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }

    /**
     * Create one monthly partition, named events_YYYY_MM.
     *
     * The name is not decoration: the maintenance command and the retention job
     * both derive it from a month, so the naming convention is load-bearing and
     * must stay in one place — app/Support/Events/EventPartitions.php mirrors it
     * and PartitionMonitorTest asserts the two agree.
     */
    private function createMonthlyPartition(Carbon $month): void
    {
        $start = $month->copy()->startOfMonth();
        $end = $start->copy()->addMonth();

        DB::statement(sprintf(
            'CREATE TABLE %s PARTITION OF events FOR VALUES FROM (%s) TO (%s)',
            sprintf('events_%s', $start->format('Y_m')),
            sprintf("'%s'", $start->toDateTimeString()),
            sprintf("'%s'", $end->toDateTimeString()),
        ));
    }
};
