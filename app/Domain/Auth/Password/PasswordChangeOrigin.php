<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

/**
 * The three doors into PasswordChanger, each with different side effects
 * (Doc 02 FR-14, FR-16, FR-17; ruling R-6).
 *
 *   Reset             all sessions revoked (the requester holds no session),
 *                     and the account unlocks — THIS account only (R-6).
 *   SelfChange        every OTHER session revoked (FR-14); the session that
 *                     proved the current password survives. No unlock — an
 *                     authenticated change proves nothing about a lockout that
 *                     was earned at the login door.
 *   InvitationAccept  first password of an invited account (FR-17). Nothing to
 *                     revoke, nothing to unlock, no history to remember.
 */
enum PasswordChangeOrigin: string
{
    case Reset = 'reset';
    case SelfChange = 'self_change';
    case InvitationAccept = 'invitation_accept';
}
