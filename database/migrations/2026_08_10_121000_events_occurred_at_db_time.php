<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `events.occurred_at` R-19 treatment — BUILD-STATUS numbered row 9's
 * FILED DEFERRAL DISCHARGING (S07 plan correction C2, final shape ruled as
 * A-33 Option B at the step-1 checkpoint): Doc 07 T-07's /v1/sync?cursor is
 * the first ordered read over `events`, so the treatment lands BEFORE the
 * endpoint exists (approved plan, step 1 vs step 5).
 *
 * WHAT THIS MIGRATION GOVERNS — the DIRECT-WRITE guard, exactly (B-2's
 * restatement):
 * - The app role loses the ability to NAME `occurred_at` (R-19a column-list
 *   grant); UPDATE is revoked outright — `events` is append-only for app.
 *   A direct app-side insert (test fixtures; no production path) takes the
 *   `clock_timestamp()` default — per-row DB time, never transaction-start.
 *
 * WHAT IT DOES NOT GOVERN — the relay's stamp (A-33's honest topology):
 * - Production events are written ONLY by OutboxRelay on the platform role,
 *   which necessarily keeps table-level INSERT (it must supply the copy;
 *   fixtures backdate through it). The relay's value is a BYTE-COPY of
 *   outbox.occurred_at — DB time at publish — because that copy is what
 *   lets the composite PK (id, occurred_at) refuse a re-driven duplicate:
 *   C-09 as database physics. That contract is pinned STRUCTURALLY (the
 *   A-33 diff assertion + byte-identical proof in
 *   EventsOccurredAtTreatmentTest; sabotage #21), stated honestly as a
 *   structural pin, not grant physics.
 * - Ordered reads get their guarantee from the T-07 watermark + UUIDv7
 *   within-stamp tiebreak (A-33 B-4, step 5), never from this stamp being
 *   relay-time. The C2 battery caught the relay-time variant breaking
 *   OutboxChaosTest — the checkpoint record and A-33 carry the full story.
 *
 * Grant form: Postgres has no "revoke one column" for a role holding
 * table-level INSERT, so REVOKE-then-column-GRANT — the tenant_keys /
 * messages idiom. The grant is checked on the partitioned PARENT, which is
 * where every write goes.
 *
 * THIS IS SABOTAGE #16's MIGRATION: dropping the revoke must turn
 * InboxGrantPhysicsTest's events guard red at the migration level.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE events ALTER COLUMN occurred_at SET DEFAULT clock_timestamp()');

        DB::statement('REVOKE INSERT, UPDATE ON events FROM app');
        DB::statement('GRANT INSERT (id, tenant_id, name, schema_version, payload, correlation_id, causation_id) ON events TO app');
        // No UPDATE grant returns: the event log is append-only for the app
        // role, the same posture the audit log holds by N-13.
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events ALTER COLUMN occurred_at SET DEFAULT now()');
        DB::statement('GRANT INSERT, UPDATE ON events TO app');
    }
};
