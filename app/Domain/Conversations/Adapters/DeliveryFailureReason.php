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
 */
enum DeliveryFailureReason: string
{
    // Non-retryable (FR-14: adapter-classified terminal refusals).
    case BlockedByUser = 'blocked_by_user';
    case WindowExpired = 'window_expired';
    case InvalidContent = 'invalid_content';

    // Retryable (transient channel conditions).
    case RateLimited = 'rate_limited';
    case NetworkError = 'network_error';
    case ChannelUnavailable = 'channel_unavailable';

    public function retryable(): bool
    {
        return match ($this) {
            self::BlockedByUser, self::WindowExpired, self::InvalidContent => false,
            self::RateLimited, self::NetworkError, self::ChannelUnavailable => true,
        };
    }
}
