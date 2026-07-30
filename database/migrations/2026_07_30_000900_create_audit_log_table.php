<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @audit-change-control: Doc01-FR-06-FR-07 (foundation audit sink; Doc 17 owns the engine)
 *
 * The minimum audit sink Doc 01 needs, with the append-only physics already real
 * (Doc 01 FR-06, FR-07; invariant N-13).
 *
 * SCOPE, STATED PLAINLY. Doc 17 owns the audit engine: hash-chaining per
 * tenant-day, anchors, the coverage harness, the immutability-physics test,
 * retention and export. This migration is NOT that. Doc 01 needs two audit
 * entries to exist at all — `tenant.provisioned` (FR-06) and the old→new
 * settings trail (FR-07) — and writing them to a table that can later be
 * UPDATEd would make the trail worthless before Doc 17 arrives to harden it.
 *
 * So the one property that cannot be retrofitted is built now: append-only by
 * DATABASE GRANT, not by developer discipline. `REVOKE UPDATE, DELETE` in the
 * RLS migration means no application code path — present or future, correct or
 * buggy — can rewrite history. Doc 17 adds the chain columns on top of rows that
 * are already immutable. What is NOT here, and is Doc 17's: hash chain, anchors,
 * four-eyes erasure, retention. Recorded as deferred in docs/BUILD-STATUS.md.
 *
 * `occurred_at` is DB-clocked (EC-08). `correlation_id` ties an entry back to the
 * request that caused it and forward to every job and event it spawned (C-14).
 * `payload` carries the old→new diff and follows SEC-07: ids, not PII, wherever a
 * reader can re-fetch by id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('action_key', 128);          // registries/audit-coverage.php
            $table->string('entity', 64);
            $table->uuid('entity_id')->nullable();      // null for tenant-level actions

            $table->string('actor_type', 24);           // staff | customer | system | platform_admin
            $table->uuid('actor_id')->nullable();       // null for system actors
            $table->string('actor_label')->nullable();  // SEC-02: platform_admin use names its actor

            $table->jsonb('payload');
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('occurred_at')->default(DB::raw('now()'));

            // No updated_at. There is no update.
            $table->timestampTz('created_at')->default(DB::raw('now()'));

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        DB::statement("
            ALTER TABLE audit_log ADD CONSTRAINT audit_log_actor_type_check
            CHECK (actor_type IN ('staff', 'customer', 'system', 'platform_admin'))
        ");

        // `audit_log` is a HOT table in ci/migration-safety.php, so its indexes
        // are raw DDL rather than schema-builder calls. On a table created empty
        // in this same migration a plain CREATE INDEX is instant and takes a lock
        // nobody is waiting on; CONCURRENTLY is for adding an index to a table
        // that already holds rows and traffic, and cannot run inside the
        // migration's transaction at all.
        DB::statement('CREATE INDEX audit_log_tenant_occurred_index ON audit_log (tenant_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_log_entity_index ON audit_log (tenant_id, entity, entity_id)');
        DB::statement('CREATE INDEX audit_log_action_key_index ON audit_log (tenant_id, action_key)');
        DB::statement('CREATE INDEX audit_log_correlation_id_index ON audit_log (correlation_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
