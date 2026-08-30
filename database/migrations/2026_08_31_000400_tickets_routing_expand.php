<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 5, item 12 — THE DOC 06 DELTA (countersigned 2026-08-30; rides the
 * STOP-A assignBySystem/PlaceInQueue packet's countersign). EXPAND phase only (C-12):
 *
 * - tickets.queue_id uuid NULLABLE — the queue a waiting/assigned ticket belongs to.
 *   Written ONLY by Doc 06's PlaceInQueue door (the one-door static sweep with positive
 *   control rides the packet). Added to the app's INSERT/UPDATE grants; the original
 *   migration's APP_WRITABLE_COLUMNS docblock is amended by reference to this file.
 * - tickets.queued_at / tickets.routed_at timestamptz(6) NULLABLE — ordering/metric
 *   relevant (FIFO base of FR-08 step 3; NFR-06's max wait; Doc 15 metrics) → DB-time,
 *   app-unwritable BOTH doors (R-19a), stamped by the FLAG-G trigger below
 *   (tickets_stamp_placement: a SECOND plain plpgsql function — the existing
 *   tickets_stamp_transition stamps status transitions; placement is not one).
 * - FKs: department_id → departments and queue_id → queues added NOT VALID (no scan of
 *   the hot table, no long lock); VALIDATE CONSTRAINT is the NAMED C-12 CARRY in
 *   BUILD-STATUS (FLAG G's condition) for the release after Doc 11's acceptance.
 * - tickets is a HOT table (ci/migration-safety.php): no schema-builder index here; the
 *   waiting-set index is the next migration, CONCURRENTLY outside a transaction.
 * - TicketGrantPhysicsTest's TICKETS_DB_TIME_COLUMNS grows by the two stamps in the SAME
 *   commit (the test derives the split from information_schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        DB::statement('ALTER TABLE tickets ADD COLUMN queue_id uuid');
        DB::statement('ALTER TABLE tickets ADD COLUMN queued_at timestamptz(6)');
        DB::statement('ALTER TABLE tickets ADD COLUMN routed_at timestamptz(6)');

        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_department_fk FOREIGN KEY (department_id) REFERENCES departments (id) NOT VALID');
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_queue_fk FOREIGN KEY (queue_id) REFERENCES queues (id) NOT VALID');

        // App may name queue_id at both doors; the two stamps at neither (R-19a/R-19b).
        DB::statement('GRANT INSERT (queue_id) ON tickets TO app');
        DB::statement('GRANT UPDATE (queue_id) ON tickets TO app');

        // FLAG G: placement stamps are the database's. NOT SECURITY DEFINER (runs as the
        // invoking role; R-20's four-definer ceiling untouched); writes NEW.* only, so the
        // column grants above stay intact; touches no status column (NFR-03's sole writer).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tickets_stamp_placement() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                -- The stamps are never app-supplied: restore OLD unconditionally first.
                NEW.queued_at := OLD.queued_at;
                NEW.routed_at := OLD.routed_at;

                -- Placed in a queue while unassigned → queued_at (FR-08 step 3's FIFO base).
                IF NEW.queue_id IS NOT NULL
                   AND (OLD.queue_id IS DISTINCT FROM NEW.queue_id)
                   AND NEW.assignee_id IS NULL THEN
                    NEW.queued_at := clock_timestamp();
                END IF;

                -- Assigned from nobody → routed_at (NFR-06's max-wait numerator ends here).
                IF OLD.assignee_id IS NULL AND NEW.assignee_id IS NOT NULL THEN
                    NEW.routed_at := clock_timestamp();
                END IF;

                -- Returned to the queue (unassigned) → the wait starts again.
                IF OLD.assignee_id IS NOT NULL AND NEW.assignee_id IS NULL AND NEW.queue_id IS NOT NULL THEN
                    NEW.queued_at := clock_timestamp();
                    NEW.routed_at := NULL;
                END IF;

                RETURN NEW;
            END;
            $fn$;
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER tickets_stamp_placement_trigger
                BEFORE UPDATE OF queue_id, assignee_id ON tickets
                FOR EACH ROW
                EXECUTE FUNCTION tickets_stamp_placement()
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tickets_stamp_placement_trigger ON tickets');
        DB::unprepared('DROP FUNCTION IF EXISTS tickets_stamp_placement()');
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_queue_fk');
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_department_fk');
        DB::statement('ALTER TABLE tickets DROP COLUMN IF EXISTS routed_at');
        DB::statement('ALTER TABLE tickets DROP COLUMN IF EXISTS queued_at');
        DB::statement('ALTER TABLE tickets DROP COLUMN IF EXISTS queue_id');
    }
};
