<?php

declare(strict_types=1);

namespace App\Domain\Auth\TwoFactor;

use Carbon\CarbonImmutable;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP verification with FR-07's tolerance and an injected clock (AC-03, EC-01).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE WINDOW IS SPEC, NOT CONFIG. FR-07 fixes "±1 time-step tolerance" and
 * EC-01 derives the product consequence (≤30 s of phone clock skew is covered;
 * beyond that, recovery codes). No setting exists to widen it, deliberately:
 * every extra step doubles an attacker's guessing window against a 6-digit
 * space, and a "helpful" ops tweak to ±4 would be invisible everywhere except
 * here.
 *
 * THE CLOCK IS A PARAMETER (CLAUDE.md §8: never assert on now()). google2fa's
 * verifyKey accepts the counter directly, so AC-03's boundary proof — ±1
 * accepted, ±2 rejected — runs at exact 30-second steps instead of sleeping
 * across them.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TotpVerifier
{
    /**
     * One TOTP time-step, fixed by RFC 6238's default and by every
     * authenticator app this must interoperate with.
     */
    private const STEP_SECONDS = 30;

    private const WINDOW = 1; // FR-07: ±1 step, closed by specification

    public function __construct(private readonly Google2FA $google2fa) {}

    public function verify(string $secret, string $code, CarbonImmutable $at): bool
    {
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return false; // FR-07: 6-digit codes only; reject before any crypto
        }

        $result = $this->google2fa->verifyKey(
            $secret,
            $code,
            self::WINDOW,
            intdiv($at->getTimestamp(), self::STEP_SECONDS),
        );

        return $result !== false;
    }
}
