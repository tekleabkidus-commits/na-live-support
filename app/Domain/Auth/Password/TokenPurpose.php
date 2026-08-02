<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

/**
 * The two purposes an auth action token can be minted for (Doc 02 SEC-02).
 *
 * SEC-02 binds every token to a purpose, and the migration's CHECK constraint
 * pins these exact strings — adding a case here without extending the
 * constraint fails at the first insert, loudly, which is the right place.
 *
 * Cross-purpose redemption is a real escalation, not a formality: the two
 * purposes have different lifetimes (FR-16's 60 minutes vs FR-17's 7 days) and
 * different outcomes (a reset unlocks the account per FR-03; an invite
 * activates one). A 7-day invite token redeemable at the reset door would
 * quietly widen the reset window twelvefold.
 */
enum TokenPurpose: string
{
    case PasswordReset = 'password_reset';
    case Invitation = 'invitation';
}
