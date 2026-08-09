<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Window;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FR-21/EC-14/BR-05: the window LEARNS. A window_expired-classified send error
 * that arrives BEFORE the stored expiry means Telegram's real window was
 * shorter than we computed — so we TIGHTEN the stored expiry to now and log
 * calibration telemetry per connection. Calibration WINS over configuration.
 *
 * BR-05 is an ASYMMETRY enforced here, not by discipline:
 *   - TIGHTEN (move expiry EARLIER, toward now) — allowed. This is the whole
 *     point: observed behaviour overrides the configured 24 h.
 *   - EXTEND (move expiry LATER than the current stored value) — REFUSED.
 *     Operators may never push the window past platform-observed behaviour.
 *
 * "now" is `clock_timestamp()` INSIDE the UPDATE (plan row 4) — the app never
 * supplies the tightening instant; the UPDATE's WHERE also guards the
 * asymmetry so a concurrent extend can't slip through.
 */
final class WindowCalibration
{
    public function __construct(
        private readonly WindowTracker $tracker,
        private readonly TenantContext $context,
    ) {}

    /**
     * EC-14: tighten to now IF the current expiry is still in the future
     * (the error proves the real window already closed). Returns true if a
     * tightening happened. Re-indexes the ZSET so the sweep sees the new
     * (earlier) expiry immediately.
     */
    public function tightenOnEarlyExpiry(string $conversationId, ?string $connectionId): bool
    {
        // clock_timestamp() is the tightening instant; the WHERE enforces
        // BR-05 — we only ever move the expiry EARLIER (to now), and only when
        // it is currently in the future.
        $row = DB::selectOne(
            'UPDATE conversations
                SET reply_window_expires_at = clock_timestamp(),
                    reply_window_state = \'expired\'
              WHERE id = ?
                AND reply_window_expires_at IS NOT NULL
                AND reply_window_expires_at > clock_timestamp()
          RETURNING reply_window_expires_at AS expiry',
            [$conversationId],
        );

        $expiry = is_object($row) ? ($row->expiry ?? null) : null;
        if (! is_string($expiry)) {
            return false; // nothing to tighten (already expired, or no window)
        }

        $this->tracker->index($conversationId, $expiry);

        Log::info('telegram window calibrated tighter', [
            'metric' => 'telegram.window.calibrated',
            'tenant_id' => $this->context->tenantId(),
            'conversation_id' => $conversationId,
            'connection_id' => $connectionId,
        ]);

        return true;
    }

    /**
     * BR-05 guard for OPERATOR edits: an operator may tighten the stored
     * expiry but NEVER extend it beyond its current value. Returns true if the
     * requested expiry was applied, false if it was refused as an extension.
     */
    public function operatorSetExpiry(string $conversationId, string $requestedExpiryIso): bool
    {
        $row = DB::selectOne(
            'UPDATE conversations
                SET reply_window_expires_at = ?::timestamptz
              WHERE id = ?
                AND reply_window_expires_at IS NOT NULL
                AND ?::timestamptz <= reply_window_expires_at
          RETURNING reply_window_expires_at AS expiry',
            [$requestedExpiryIso, $conversationId, $requestedExpiryIso],
        );

        $expiry = is_object($row) ? ($row->expiry ?? null) : null;
        if (! is_string($expiry)) {
            Log::info('telegram window extend refused (BR-05)', [
                'metric' => 'telegram.window.extend_refused',
                'conversation_id' => $conversationId,
            ]);

            return false;
        }

        $this->tracker->index($conversationId, $expiry);

        return true;
    }
}
