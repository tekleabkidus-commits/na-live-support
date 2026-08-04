<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One role per user — Doc 03 FR-02/BR-01, as PHYSICS: a single nullable
 * column, no pivot table anywhere. Multi-role cannot be expressed in this
 * schema; composite needs are a new role or an override, never a second row.
 *
 * Nullable, expand-contract (C-12): users exist since Doc 02, roles arrive
 * with this session's seeder. A NULL role resolves to NO permissions —
 * implicit deny, fail-closed (FR-05) — so an unmigrated user can never act
 * by accident. BR-07: service accounts use this same column; no special
 * system role exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // FORCE RLS applies to the FK-validation scan even for the schema
        // owner; the migration session pins a nil tenant like every other
        // rbac migration (the scan matches zero rows — the column is new).
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('role_id')->nullable();

            $table->foreign('role_id')->references('id')->on('roles');
        });

        DB::statement('CREATE INDEX users_role_id_index ON users (role_id)');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });

        DB::statement('DROP INDEX IF EXISTS users_role_id_index');
    }
};
