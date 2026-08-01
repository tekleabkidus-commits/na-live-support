<?php

declare(strict_types=1);

namespace App\Support\Auth\Sessions;

use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;

/**
 * FR-11's two timeouts, as pure functions over timestamps.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TWO CLOCKS, NOT ONE, AND THEY EXPIRE DIFFERENT THINGS.
 *
 *   IDLE (auth.session.idle_minutes, default 480) measures since the last
 *   request. It protects the unattended desk: an agent walks away and their
 *   session dies whether or not anyone touches it.
 *
 *   ABSOLUTE (auth.session.absolute_hours, default 72) measures since
 *   AUTHENTICATION and cannot be refreshed by activity. It bounds the damage of
 *   a stolen session that is being actively used — which is precisely the case
 *   the idle timeout never fires for, because the thief keeps it warm.
 *
 * A session that only had an idle timeout would live forever in an attacker's
 * hands. That is why both exist, and why the absolute one must never be reset
 * on activity, however tempting that looks when someone complains about being
 * logged out mid-shift.
 *
 * THE CLOCK IS A PARAMETER (CLAUDE.md §8). Nothing here reads now(), so the
 * boundaries are testable exactly — at 479 and 481 minutes — rather than by
 * sleeping, and a test can prove the absolute limit fires on a session that has
 * been active every minute of its life.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SessionLifetimePolicy
{
    public const ESTABLISHED_AT = 'auth.established_at';

    public const LAST_ACTIVITY_AT = 'auth.last_activity_at';

    public function __construct(private readonly SettingsRegistry $registry) {}

    public function hasExpired(
        ?CarbonImmutable $establishedAt,
        ?CarbonImmutable $lastActivityAt,
        CarbonImmutable $now,
    ): bool {
        return $this->exceededIdle($lastActivityAt, $now)
            || $this->exceededAbsolute($establishedAt, $now);
    }

    public function exceededIdle(?CarbonImmutable $lastActivityAt, CarbonImmutable $now): bool
    {
        // A session with no recorded activity is not expired — it was just
        // established. Treating "unknown" as "expired" would log people out on
        // the request immediately after login.
        if ($lastActivityAt === null) {
            return false;
        }

        return $lastActivityAt->addMinutes($this->idleMinutes())->lessThanOrEqualTo($now);
    }

    public function exceededAbsolute(?CarbonImmutable $establishedAt, CarbonImmutable $now): bool
    {
        if ($establishedAt === null) {
            return false;
        }

        return $establishedAt->addHours($this->absoluteHours())->lessThanOrEqualTo($now);
    }

    public function idleMinutes(): int
    {
        return $this->intSetting('auth.session.idle_minutes', 480);
    }

    public function absoluteHours(): int
    {
        return $this->intSetting('auth.session.absolute_hours', 72);
    }

    public function maxConcurrent(): int
    {
        return $this->intSetting('auth.session.max_concurrent', 5);
    }

    private function intSetting(string $key, int $fallback): int
    {
        $default = $this->registry->definition($key)['default'];

        return is_numeric($default) ? (int) $default : $fallback;
    }
}
