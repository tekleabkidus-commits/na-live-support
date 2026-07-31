<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The users table's AUTH shape (Doc 02 — inherited task 4 from Doc 01).
 *
 * Doc 01 owned this table's conventions and its tenancy and explicitly handed the
 * rest to Doc 02 (see User.php's docblock and 0001_01_01_000000's note). This is
 * that handover: account state, 2FA enrolment, lockout state, and the per-user
 * locale the C-08 chain reads.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY LOCKOUT IS COLUMNS AND NOT A REDIS COUNTER.
 *
 * FR-03 is usually built as an incrementing cache key, and the session plan calls
 * that out as wrong: "lockout is a state machine, not a cache counter". The
 * difference is observable. AC-02 requires that an admin unlock is AUDITED and
 * that a completed password reset unlocks the account — both are transitions with
 * an actor and a reason, and neither is expressible as "a number went back to
 * zero". EC-11 additionally requires that a victim can always self-unlock, which
 * means the lock has to be a fact someone can act on rather than a TTL nobody can
 * see. And R-05 forbids auth state living in worker memory.
 *
 * Redis still carries the FAST path — the per-IP throttle (FR-03's independent
 * 20/min) and the rolling attempt window are O(1) counters there per §12 — but the
 * authoritative "is this account locked, since when, why" lives here, where it
 * survives a Redis flush (FS-01) and can be audited.
 *
 * EXPAND-CONTRACT (C-12): every column is added NULLABLE or with a default and
 * nothing is dropped or narrowed, so this is a pure expand phase and needs no
 * follow-up release. `disabled_at` is deliberately a nullable timestamp rather
 * than a boolean `is_active`: BR-04 has to record WHEN, and a boolean would throw
 * that away.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // ── Account state ────────────────────────────────────────────────
            //
            // FR-17: admin-created users start INVITED and have never chosen a
            // password. A NULL password_set_at is what distinguishes "invited,
            // link outstanding" from "active" without a second status column that
            // could disagree with reality.
            $table->timestampTz('password_set_at')->nullable();

            // BR-04 / EC-05 / FR-05: disabling revokes sessions and tokens
            // atomically; the timestamp is the audit-visible fact.
            $table->timestampTz('disabled_at')->nullable();

            // FR-15's max-age policy compares against this. Distinct from
            // password_set_at only in that an admin-forced expiry can move it.
            $table->timestampTz('password_changed_at')->nullable();

            // ── Locale (inherited task 5 — the C-08 chain's first hop) ────────
            //
            // NULL means "no preference": the chain then falls through to the
            // tenant default and finally English, exactly as C-08 specifies. A
            // default of 'en' here would silently pin every user to English and
            // make the tenant-default hop unreachable.
            $table->string('locale', 8)->nullable();

            // ── Two-factor (FR-06/FR-07, SEC-03) ─────────────────────────────
            //
            // The secret is encrypted at rest (Laravel's encrypted cast), NOT
            // hashed: TOTP verification needs the original value, which is the
            // one credential in this document that cannot be a one-way hash.
            // That is also why BR-02 exists — nobody, including an admin, has a
            // read path to it; the only operations are enrol and verify.
            //
            // confirmed_at separates "enrolment started" from "enrolment
            // completed": FR-06 requires one valid code before 2FA is active,
            // and EC-10's single-secret-wins race is resolved on this column.
            $table->text('two_factor_secret')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();

            // ── Lockout state machine (FR-03, AC-02, EC-11) ──────────────────
            //
            // locked_until NULL = not locked. The window's failure count is kept
            // here too so FS-01 (Redis down) degrades to fail-closed with the
            // real state intact rather than silently forgetting attempts.
            $table->smallInteger('failed_login_attempts')->default(0);
            $table->timestampTz('first_failed_login_at')->nullable();
            $table->timestampTz('locked_until')->nullable();
        });

        // Partial index: the overwhelming majority of rows have locked_until NULL,
        // and the only query that reads it asks "is this account currently locked".
        DB::statement('CREATE INDEX users_locked_until_index ON users (locked_until) WHERE locked_until IS NOT NULL');

        // Attempt counts are never negative — a decrement bug becomes an error at
        // the boundary instead of an account that can never lock.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_failed_login_attempts_check CHECK (failed_login_attempts >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_failed_login_attempts_check');
        DB::statement('DROP INDEX users_locked_until_index');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'password_set_at',
                'disabled_at',
                'password_changed_at',
                'locale',
                'two_factor_secret',
                'two_factor_confirmed_at',
                'failed_login_attempts',
                'first_failed_login_at',
                'locked_until',
            ]);
        });
    }
};
