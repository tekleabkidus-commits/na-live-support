<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 6 — core-edit packet #3 (Doc 01; countersigned 2026-09-01):
 * the ENVELOPE gains an optional `metadata` jsonb on `outbox` and `events` —
 * Doc 12 T-03's `automation_depth` rides HERE, beside correlation/causation,
 * never inside a payload (the catalog's closed-payload law is untouched; the
 * smuggled-key control in EnvelopeMetadataTest proves it still refuses).
 *
 * ADDITIVE ONLY (C-12 expand): nullable columns, no backfill, no contract
 * change — every existing publisher and consumer is byte-untouched. The app's
 * table-level INSERT on outbox covers the new column; `events` is written by
 * the relay on the platform connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE outbox ADD COLUMN metadata jsonb');
        DB::statement('ALTER TABLE events ADD COLUMN metadata jsonb');
        // The envelope stays symmetric for the app role: like correlation/causation,
        // metadata is app-suppliable; occurred_at remains the ONLY app-denied events
        // column (the Doc 07 InboxGrantPhysicsTest row-9 pin holds byte-untouched).
        DB::statement('GRANT INSERT (metadata) ON events TO app');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events DROP COLUMN IF EXISTS metadata');
        DB::statement('ALTER TABLE outbox DROP COLUMN IF EXISTS metadata');
    }
};
