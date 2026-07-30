<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff users belong to exactly one tenant (Doc 01 FR-02, FR-03a).
 *
 * SCOPE NOTE. Doc 02 owns authentication mechanics — sessions, 2FA, lockout,
 * enumeration defence — and this migration touches none of that. What it does
 * own is tenancy: FR-02 says every persistent business record carries a
 * non-null tenant_id, and FR-03(a) resolves a staff request through
 * "token belongs to a user; user belongs to a tenant". That edge has to exist
 * before any resolution path can be built or tested, so it is foundation work.
 * The `users` table's shape beyond this column stays Doc 02's.
 *
 * EXPAND-CONTRACT NOTE (C-12). This adds a NOT NULL column in a single step
 * rather than the add-nullable → backfill → enforce dance. That sequence exists
 * to protect rows that already exist in production; this project has shipped no
 * release and the table is empty, so there is nothing to backfill and no lock
 * to worry about. Doing the three-release dance against zero rows would be
 * theatre, and theatre in a migration teaches the next person the wrong lesson.
 * The moment there is a production row, the rule applies literally.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard the assumption the comment above rests on, rather than
        // asserting it in prose and hoping.
        $existing = (int) DB::table('users')->count();
        if ($existing > 0) {
            throw new RuntimeException(sprintf(
                'users already holds %d row(s): the single-step NOT NULL add in this migration is only '
                .'safe on an empty table. Split it into add-nullable → backfill → enforce (C-12).',
                $existing,
            ));
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        DB::statement('CREATE INDEX users_tenant_id_index ON users (tenant_id)');

        // Email is unique PER TENANT, not globally: two operators may both
        // employ someone@example.com, and a globally-unique email would leak
        // the existence of one tenant's staff to another at signup time.
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_email_unique');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_tenant_id_email_unique UNIQUE (tenant_id, email)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_tenant_id_email_unique');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
