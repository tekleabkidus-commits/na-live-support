<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Domain\Conversations\Adapters\AdapterError;
use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Telegram\Api\TelegramApiError;
use App\Domain\Telegram\Api\TelegramTransportError;

/**
 * FR-18: Telegram's error surface mapped into the frozen DeliveryFailureReason
 * table — the adapter classifies, the Doc 04 state machine acts. Landed at
 * step 3 because the conformance suite asserts the table on every inducible
 * class; step 7 hardens the description patterns against the AC-05 fixture
 * corpus (each Telegram error fixture must land in exactly the state and
 * reason FR-18 specifies, incl. EC-08's doomed-retry avoidance).
 *
 * The table, per FR-18's text:
 *   403                          -> blocked_by_user      (non-retryable)
 *   400 window-closed shapes     -> window_expired       (non-retryable)
 *   400 connection-paused shapes -> connection_paused    (non-retryable, A-20)
 *   other 400                    -> invalid_content      (non-retryable, detail surfaced)
 *   429 (+retry_after)           -> rate_limited         (retryable; header honored over backoff, T-08)
 *   5xx                          -> channel_unavailable  (retryable)
 *   transport failure            -> network_error        (retryable)
 * credentials_unavailable (FS-03) is not an API answer — the adapter maps it
 * where token resolution itself fails.
 */
final class ErrorClassifier
{
    public static function classify(TelegramApiError|TelegramTransportError $error): AdapterError
    {
        if ($error instanceof TelegramTransportError) {
            return new AdapterError(DeliveryFailureReason::NetworkError);
        }

        if ($error->httpStatus === 403) {
            return new AdapterError(DeliveryFailureReason::BlockedByUser);
        }

        if ($error->httpStatus === 429) {
            return new AdapterError(DeliveryFailureReason::RateLimited, $error->retryAfter);
        }

        if ($error->httpStatus >= 500) {
            return new AdapterError(DeliveryFailureReason::ChannelUnavailable);
        }

        $description = strtoupper($error->description);

        if (str_contains($description, 'REPLY_WINDOW') || str_contains($description, 'WINDOW_EXPIRED')) {
            return new AdapterError(DeliveryFailureReason::WindowExpired);
        }

        if (str_contains($description, 'CONNECTION_PAUSED') || str_contains($description, 'BUSINESS_PEER_PAUSED')) {
            return new AdapterError(DeliveryFailureReason::ConnectionPaused);
        }

        if ($error->httpStatus >= 400) {
            return new AdapterError(DeliveryFailureReason::InvalidContent);
        }

        return new AdapterError(DeliveryFailureReason::ChannelUnavailable);
    }
}
