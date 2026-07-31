<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2FA recovery codes, hashed (Doc 02 FR-06, FR-07, SEC-03; owner ruling Q6).
 *
 * SEC-03: "Recovery codes hashed (argon2id); displayed exactly once."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS TABLE EXISTS AT ALL — i.e. why Fortify's own storage was replaced.
 *
 * Stock Fortify keeps recovery codes in a `two_factor_recovery_codes` column as
 * an ENCRYPTED JSON array, and ships a route that DECRYPTS and re-displays them.
 * That is a deliberate product choice on Fortify's part and it directly
 * contradicts two of this platform's rules:
 *
 *   SEC-03 "displayed exactly once" — a re-display endpoint means the codes are
 *          displayable forever, so "exactly once" becomes a UI convention.
 *   BR-02  "Admins can never view … another user's password or 2FA secret" —
 *          anything reversible is, by definition, viewable by whoever holds the
 *          key, and the app holds the key.
 *
 * Hashing makes both structural: after enrolment there is no value in the system
 * that can be shown to anyone. Verification hashes the presented code and matches;
 * losing the codes means re-enrolling, which is the correct and intended cost.
 *
 * ARGON2ID, not SHA-256, and the reason is entropy: a recovery code is short
 * enough for a human to type from paper (~10 characters, ~50 bits), so a fast
 * hash over a stolen table is brute-forceable offline. The action-token table
 * next door uses SHA-256 precisely because those tokens carry 256 bits and a slow
 * KDF there would buy nothing while making the lookup unindexable.
 *
 * CONSUMPTION IS A COLUMN, NOT A DELETE (FR-07's "shows remaining count", BR-02's
 * no-hard-delete posture): `used_at` records that a code was spent, so the count
 * of remaining codes is a query and the fact of use is auditable. `app` holds no
 * DELETE on this table, so "consumed" cannot be quietly rewritten as "never
 * existed".
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', true)");

        Schema::create('user_recovery_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();          // C-01
            $table->uuid('tenant_id');              // C-02
            $table->uuid('user_id');

            // argon2id digest. No plaintext column exists anywhere in this
            // schema — GrantPhysicsTest asserts that as a catalog fact, so a
            // future "just cache the plaintext for the download button" cannot
            // land quietly.
            $table->string('code_hash', 255);

            // FR-07: "consumes one code and shows remaining count".
            $table->timestampTz('used_at')->nullable();

            // Which enrolment generated this batch. FR-06 regenerates all eight
            // at once, and EC-10's single-secret-wins race needs to tell a
            // superseded batch from the live one without deleting anything.
            $table->uuid('batch_id');

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });

        // Verification reads a user's unused codes; the partial index keeps that
        // to the live batch rather than the account's whole history.
        DB::statement('CREATE INDEX user_recovery_codes_live_index ON user_recovery_codes (user_id) WHERE used_at IS NULL');

        // BR-02 / SEC-03: spent codes are never erased.
        DB::statement('REVOKE DELETE ON user_recovery_codes FROM app');

        DB::statement('ALTER TABLE user_recovery_codes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE user_recovery_codes FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON user_recovery_codes
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recovery_codes');
    }
};
