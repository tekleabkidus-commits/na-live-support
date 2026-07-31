<?php

declare(strict_types=1);

namespace App\Domain\Auth\Login;

/**
 * FR-04's `auth.login.failed` reason categories, as a closed set.
 *
 * The spec lists them verbatim: bad_credentials, locked, suspended_tenant,
 * disabled_user, ip_blocked, 2fa_failed. They are an enum rather than strings
 * because Docs 15/17/18 build reports, audit views and alerts against these
 * exact values (§21: "Freeze auth event names now") — a typo'd category would
 * silently create a seventh bucket nobody dashboards.
 *
 * WHAT THE CATEGORY IS FOR, AND WHAT IT IS NOT FOR. It is recorded in the audit
 * trail and the event payload, where an operator's security officer needs to
 * distinguish "someone is guessing passwords" from "a disabled account keeps
 * trying" (US-06). It NEVER reaches the person at the login form: FR-02 and
 * AC-01 require one generic message for every credential failure, so the
 * category is an internal fact, not a response body.
 *
 * The two exceptions are deliberate and specified: FR-05 gives disabled users
 * and suspended tenants distinct, non-technical screens — and both are only
 * reachable AFTER the password verified, so neither can be used to enumerate.
 */
enum FailureReason: string
{
    case BadCredentials = 'bad_credentials';
    case Locked = 'locked';
    case SuspendedTenant = 'suspended_tenant';
    case DisabledUser = 'disabled_user';
    case IpBlocked = 'ip_blocked';
    case TwoFactorFailed = '2fa_failed';

    /**
     * Does this failure get its own screen (FR-05), or the generic one (FR-02)?
     */
    public function hasDistinctMessage(): bool
    {
        return match ($this) {
            self::DisabledUser, self::SuspendedTenant => true,
            default => false,
        };
    }

    public function messageI18nKey(): string
    {
        return sprintf('auth.failed.%s', $this->value);
    }
}
