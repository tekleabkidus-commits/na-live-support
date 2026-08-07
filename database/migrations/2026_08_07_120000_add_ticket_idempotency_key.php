<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-13's idempotency key — "Automation (Doc 12) and API (POST /v1/tickets): same
 * validation path; source recorded; duplicates prevented via idempotency keys."
 *
 * A-7 AMENDMENT, FILED. The base field list interpretation was derived from Doc 06's
 * own text and this column was missed on the first pass: FR-13 requires the mechanism
 * in as many words, so the omission was mine, not the document's. Recorded in
 * ANSWERS-FROM-DOC-AUTHOR.md beside A-7's other amendment rather than slipped in.
 *
 * WHY A COLUMN AND NOT A SEPARATE TABLE. The uniqueness that matters is "one ticket
 * per key per tenant", and a partial unique index on the tickets row states exactly
 * that, in the same place the duplicate would otherwise appear. A side table would
 * need its own transaction discipline to stay in step with the row it guards — one
 * more thing to get wrong, guarding one column.
 *
 * PARTIAL, because the key is NULL for the paths that have none: an agent creating a
 * ticket from a conversation (FR-12) is a human deciding once, and NULLs would collide
 * in a plain unique index the moment two of them existed.
 *
 * tickets is a HOT table (ci/migration-safety.php): raw DDL for the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->text('idempotency_key')->nullable();
        });

        // The app writes this one (it is a caller-supplied identifier, not a clock).
        DB::statement('GRANT INSERT (idempotency_key) ON tickets TO app');
        DB::statement('GRANT UPDATE (idempotency_key) ON tickets TO app');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tickets_tenant_idempotency_key_unique
                ON tickets (tenant_id, idempotency_key)
             WHERE idempotency_key IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tickets_tenant_idempotency_key_unique');
    }
};
