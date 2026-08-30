<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 5, item 12 (continued): the waiting-set index on the HOT tickets table,
 * built CONCURRENTLY outside a transaction (ci/migration-safety.php's hot-table rule).
 * FS-01's rebuild ("waiting = open-family unassigned per queue") and the dispatcher's
 * DB-truth reads walk exactly this partial index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS tickets_queue_waiting
                ON tickets (tenant_id, queue_id, queued_at)
                WHERE assignee_id IS NULL
                  AND status IN ('new', 'open', 'pending_customer', 'pending_internal', 'escalated')
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS tickets_queue_waiting');
    }
};
