<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * FR-15's reuse memory (default: the last 5, hashed).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT "THE LAST N" MEANS HERE, precisely, because two readings exist:
 * the history table stores PREVIOUS passwords (the migration's contract — a row
 * is written at the moment a password stops being current), and the reuse check
 * refuses the CURRENT password plus the most recent N history rows. The current
 * password is always refused regardless of N — "change" to the same value is
 * not a change, and letting it through would reset password_changed_at (and
 * FR-15's max-age clock) without anything actually changing.
 *
 * THE COST IS DELIBERATE AND MUST STAY OFF THE LOGIN PATH. Each comparison is
 * an argon2id verification at NFR-03's ~100 ms tuning, so a change against a
 * depth-5 history costs up to ~600 ms. Acceptable for a rare, human-initiated
 * action; never acceptable per request. Nothing here is callable from the
 * login pipeline.
 *
 * NO DELETE, NO TRIM. `app` holds no DELETE grant (BR-02), so rows beyond the
 * configured depth simply stop being read — rememberCurrent() appends, and
 * isReused() takes the newest N by created_at (DB time, C-11). The platform
 * retention path owns physical pruning, like every other append-only table.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class PasswordHistory
{
    /**
     * True if the candidate matches the current password or any of the most
     * recent $depth previous ones. Call with the tenant already bound.
     */
    public function isReused(User $user, string $candidate, int $depth): bool
    {
        $rawCurrent = $user->getRawOriginal('password');
        $current = is_string($rawCurrent) ? $rawCurrent : '';

        if ($current !== '' && Hash::check($candidate, $current)) {
            return true;
        }

        if ($depth <= 0) {
            return false;
        }

        /** @var list<object{password_hash: string}> $rows */
        $rows = DB::select(
            'SELECT password_hash FROM user_password_history
             WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?',
            [$user->identifier(), $depth],
        );

        foreach ($rows as $row) {
            if (Hash::check($candidate, $row->password_hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Archive the hash that is about to stop being current. Called by
     * PasswordChanger with the PRE-change hash, before the new one is saved —
     * afterwards the model no longer knows it.
     *
     * `created_at` is deliberately NOT passed: the column's `DEFAULT now()`
     * stamps DB time, and "which N are the last N" is an ordering decision
     * that C-11 keeps off app clocks.
     */
    public function remember(User $user, string $previousHash): void
    {
        if ($previousHash === '') {
            return; // an invited account setting its first password has no previous
        }

        DB::insert(
            'INSERT INTO user_password_history (id, tenant_id, user_id, password_hash)
             VALUES (?, ?, ?, ?)',
            [
                (string) \Illuminate\Support\Str::uuid7(),
                $user->tenantId(),
                $user->identifier(),
                $previousHash,
            ],
        );
    }
}
