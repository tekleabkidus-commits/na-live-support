<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * The adapter error-classification table (Doc 04 FR-13/FR-14) — a frozen public
 * contract alongside the state machine (§21): Doc 05/07/13 map these codes to UI
 * guidance (`messaging.fail.*` i18n keys), so adding or re-classifying a code requires
 * a series-wide review.
 *
 * Non-retryable codes take a message to failed(final) IMMEDIATELY (FR-14); retryable
 * codes enter the ×5 backoff (1s, 5s, 30s, 2m, 10m) before failed(final) (FR-13).
 * The classification lives HERE, on the enum, so an adapter cannot ship an
 * inconsistent {code, retryable} pair — the conformance suite asserts this table.
 *
 * SERIES-REVIEW RECORD (ruling A-20, S23 plan approval): `connection_paused`
 * and `credentials_unavailable` entered by advisor ruling because both are
 * DOCUMENT-REQUIRED verbatim — Doc 05 FR-18 names connection_paused
 * non-retryable; Doc 05 FS-03 names credentials_unavailable retryable with
 * no fallback storage. The conformance expected-table gained both rows in
 * the same commit; the overload alternative was rejected as
 * requirement-simplification.
 */
enum DeliveryFailureReason: string
{
    // Non-retryable (FR-14: adapter-classified terminal refusals).
    case BlockedByUser = 'blocked_by_user';
    case WindowExpired = 'window_expired';
    case InvalidContent = 'invalid_content';
    case ConnectionPaused = 'connection_paused';

    // Retryable (transient channel conditions).
    case RateLimited = 'rate_limited';
    case NetworkError = 'network_error';
    case ChannelUnavailable = 'channel_unavailable';
    case CredentialsUnavailable = 'credentials_unavailable';

    public function retryable(): bool
    {
        return match ($this) {
            self::BlockedByUser, self::WindowExpired, self::InvalidContent, self::ConnectionPaused => false,
            self::RateLimited, self::NetworkError, self::ChannelUnavailable, self::CredentialsUnavailable => true,
        };
    }
}
