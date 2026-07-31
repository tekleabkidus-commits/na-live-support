<?php

declare(strict_types=1);

namespace App\Domain\Auth\Lockout;

use Carbon\CarbonImmutable;

/**
 * One account's lockout state (Doc 02 FR-03, AC-02).
 *
 * An immutable value: every transition returns a NEW state rather than mutating
 * this one, so a caller cannot half-apply a transition and leave the machine in
 * a shape no rule produced. That matters because the same state is written to
 * the database and read back by the pre-tenant login door — the two must agree
 * exactly, and a mutable object with setters invites them to drift.
 *
 * The three fields are the whole machine:
 *
 *   failedAttempts     how many failures inside the CURRENT window
 *   firstFailureAt     when the current window opened (null = no window open)
 *   lockedUntil        null = not locked; a time in the future = locked
 *
 * "Locked" is deliberately a TIMESTAMP rather than a boolean plus a job to clear
 * it. A boolean needs someone to unset it, so a missed job leaves an account
 * locked forever; a timestamp expires by arithmetic, which cannot fail to run.
 */
final readonly class LockoutState
{
    public function __construct(
        public int $failedAttempts,
        public ?CarbonImmutable $firstFailureAt,
        public ?CarbonImmutable $lockedUntil,
    ) {}

    public static function fresh(): self
    {
        return new self(0, null, null);
    }

    /**
     * Rebuild from the columns the login door returns.
     */
    public static function fromCandidate(
        int $failedAttempts,
        ?CarbonImmutable $firstFailureAt,
        ?CarbonImmutable $lockedUntil,
    ): self {
        return new self($failedAttempts, $firstFailureAt, $lockedUntil);
    }

    public function isLockedAt(CarbonImmutable $now): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil->greaterThan($now);
    }
}
