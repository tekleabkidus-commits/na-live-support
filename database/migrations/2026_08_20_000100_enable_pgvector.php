<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA AMENDMENT 3 / ADR 0015 — the pgvector extension (Doc 09 T-05).
 *
 * MIGRATOR-OWNED DDL (ADR 0003 grant physics): the extension installs on the
 * migration connection; the app role gains nothing new. pgvector is a TRUSTED
 * extension, so the migrator's CREATE right on the database suffices — no
 * superuser step outside the migration path. The image prerequisite
 * (pgvector/pgvector:pg16, a drop-in Postgres 16) rides this same commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
