<?php

declare(strict_types=1);

namespace App\Support\Settings\Exceptions;

use RuntimeException;

/**
 * Two admins wrote the same setting concurrently and this caller lost (Doc 01 EC-06).
 *
 * EC-06: "Settings write race (two admins) → last-write-wins with both writes audited and
 * a conflict toast to the loser (compare updated_at optimistic check)."
 *
 * Three requirements in one sentence, and this exception is the third. Last-write-wins
 * decides the value; both writes are audited so neither edit vanishes from history; and
 * the loser must be TOLD — otherwise they close the tab believing their change is live,
 * and the divergence between what they think they configured and what the platform is
 * doing can persist indefinitely.
 *
 * Carries the winning value so the caller can show "someone else set this to X" rather
 * than a bare failure, which is what makes it a useful toast rather than a scary one.
 */
final class SettingWriteConflict extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $key,
        public readonly mixed $attemptedValue,
        public readonly mixed $winningValue,
    ) {
        parent::__construct($message);
    }

    public static function forKey(string $key, mixed $attempted, mixed $winning): self
    {
        return new self(
            sprintf(
                'Setting "%s" was changed by someone else while this edit was in flight (Doc 01 EC-06). '
                .'The other write won; this one was audited and not applied.',
                $key,
            ),
            $key,
            $attempted,
            $winning,
        );
    }
}
