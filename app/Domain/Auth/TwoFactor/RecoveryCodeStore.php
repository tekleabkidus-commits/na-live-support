<?php

declare(strict_types=1);

namespace App\Domain\Auth\TwoFactor;

use App\Models\User;
use App\Models\UserRecoveryCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The hashed recovery-code store (Doc 02 FR-06, FR-07, SEC-03; rulings Q6, R-4).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PLAINTEXT EXISTS FOR ONE RESPONSE. generate() returns the eight codes exactly
 * once — the enrolment response that shows/downloads them — and stores argon2id
 * digests (the app's configured hasher, pinned by config/hashing.php). No read
 * path can produce plaintext again; GrantPhysicsTest pins that no readable
 * column exists (Q6).
 *
 * THE LIVE BATCH IS THE NEWEST BATCH. FR-06 regenerates all eight at once and
 * EC-10's single-secret-wins needs superseded batches to become dead without
 * deleting anything (BR-02: no DELETE grant). So verification and R-4's count
 * consult only the most recent batch_id; older batches remain as history.
 *
 * R-4 (owner ruling): the remaining count is COMPUTED from `used_at IS NULL`
 * rows, never cached and never a counter — a counter drifts, and it drifts in
 * the direction that hides "you are about to run out".
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RecoveryCodeStore
{
    public const BATCH_SIZE = 8; // FR-06

    /**
     * FR-07: "at 2 codes remaining, banner prompts regeneration".
     */
    public const REGENERATION_PROMPT_AT = 2;

    /**
     * Mint a fresh batch, superseding any previous one.
     *
     * @return list<string> the plaintext codes — shown once, never stored
     */
    public function generate(User $user, CarbonImmutable $now): array
    {
        $batchId = (string) Str::uuid7();
        $codes = [];

        for ($i = 0; $i < self::BATCH_SIZE; $i++) {
            // 10 hex chars in XXXXX-XXXXX shape: 40 bits, printable, phone-
            // typable, self-evidently not a TOTP code (§8's recovery-code grid).
            $plain = strtoupper(bin2hex(random_bytes(5)));
            $plain = substr($plain, 0, 5).'-'.substr($plain, 5);

            UserRecoveryCode::query()->create([
                'user_id' => $user->identifier(),
                'code_hash' => Hash::make($plain),
                'batch_id' => $batchId,
            ]);

            $codes[] = $plain;
        }

        return $codes;
    }

    /**
     * FR-07's recovery path: spend one code from the live batch.
     *
     * Returns whether a code matched (and was marked used). Comparison walks
     * every unused live-batch row through Hash::check rather than stopping at
     * shape mismatches — the cost is bounded by BATCH_SIZE and the per-IP
     * throttle already caps attempts upstream.
     */
    public function consume(User $user, string $plaintext, CarbonImmutable $now): bool
    {
        foreach ($this->liveBatchUnused($user) as $row) {
            if (Hash::check($plaintext, $row->code_hash)) {
                $row->used_at = $now;
                $row->save();

                return true;
            }
        }

        return false;
    }

    /**
     * R-4: remaining = live-batch rows where used_at IS NULL. A query, always.
     */
    public function remaining(User $user): int
    {
        return count($this->liveBatchUnused($user));
    }

    /**
     * @return list<UserRecoveryCode>
     */
    private function liveBatchUnused(User $user): array
    {
        $liveBatchId = UserRecoveryCode::query()
            ->where('user_id', $user->identifier())
            ->orderByDesc('id') // UUIDv7: newest batch's rows sort last (C-11)
            ->value('batch_id');

        if (! is_string($liveBatchId)) {
            return [];
        }

        return array_values(UserRecoveryCode::query()
            ->where('user_id', $user->identifier())
            ->where('batch_id', $liveBatchId)
            ->whereNull('used_at')
            ->get()
            ->all());
    }
}
