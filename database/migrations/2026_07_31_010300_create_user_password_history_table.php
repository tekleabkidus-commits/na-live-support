<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Password reuse history (Doc 02 FR-15).
 *
 * FR-15: "disallow reuse of last auth.password.history (default 5, hashed)".
 *
 * The word that shapes this table is HASHED. Reuse checking means comparing a
 * candidate password against previous ones, and the only safe way to do that is
 * to keep the old argon2id digests and verify against each — never to store
 * anything reversible. So this table holds exactly what `users.password` holds,
 * for the previous N passwords, and nothing else: no plaintext, no hint, no
 * "changed from" description.
 *
 * A CONSEQUENCE WORTH STATING, because it looks like a bug otherwise: checking
 * reuse against 5 history rows costs 5 argon2id verifications, and NFR-03 tunes
 * argon2id to ~100 ms deliberately. A password CHANGE therefore takes ~600 ms in
 * the worst case. That is acceptable — it is a rare, deliberate, human-initiated
 * action — but it must never be moved onto the LOGIN path, which NFR-01 budgets
 * at 400 ms p99 for one verification.
 *
 * Rows are trimmed to the configured depth by the application when a new password
 * is set. `app` holds no DELETE (BR-02), so trimming happens through the platform
 * retention path, and the interim state — more rows than the policy depth — is
 * harmless because the reuse check reads the most recent N by `created_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('user_password_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();      // C-01
            $table->uuid('tenant_id');          // C-02
            $table->uuid('user_id');

            // argon2id digest of a password that is no longer current.
            $table->string('password_hash', 255);

            // C-03/C-11: DB time, never an app clock — the ordering here decides
            // which N are "the last N".
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });

        DB::statement('CREATE INDEX user_password_history_user_recent_index ON user_password_history (user_id, created_at DESC)');

        DB::statement('REVOKE DELETE ON user_password_history FROM app');

        DB::statement('ALTER TABLE user_password_history ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE user_password_history FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON user_password_history
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_password_history');
    }
};
