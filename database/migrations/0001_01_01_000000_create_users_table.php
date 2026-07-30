<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel scaffolding, corrected to the global conventions (Doc 01 §10, C-01/C-03).
 *
 * WHY THIS FILE WAS AMENDED RATHER THAN PATCHED BY A LATER MIGRATION.
 * As generated, this migration violated two conventions that Doc 01 makes
 * platform-wide law:
 *
 *   C-01  UUIDv7 primary keys on every table — `$table->id()` is a bigint.
 *   C-03  all timestamps timestamptz — `$table->timestamps()` is `timestamp`.
 *
 * Correcting a bigint pk from a follow-up migration means DROP COLUMN, which
 * the migration-safety gate blocks unless it carries an
 * `@expand-contract: contract-phase` marker — and that marker would be a lie,
 * because no expand or backfill release has shipped. The alternative is to let
 * the violation stand and let it spread: `users.id` is referenced by
 * `tenant_settings.updated_by` and `audit_log.actor_id`, so a bigint here makes
 * every actor column in the platform a bigint, and C-01 becomes unreachable
 * without a data migration later.
 *
 * C-12 (expand-contract) protects rows that exist in production. This project
 * has shipped no release; `make fresh` and CI both build the schema from zero.
 * So the honest fix is to correct the scaffolding at its source, once, before
 * anything depends on it — which is what this does.
 *
 * Doc 02 still owns this table's SHAPE (2FA columns, lockout state, session
 * mechanics). Doc 01 owns only its conventions and its tenancy, and the
 * tenant_id column arrives in 2026_07_30_000400.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();                 // C-01
            $table->string('name');
            $table->string('email')->unique();             // re-scoped per-tenant in 000400
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestampsTz();                        // C-03
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
