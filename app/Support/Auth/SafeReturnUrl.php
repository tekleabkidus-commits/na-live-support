<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * The FR-11 return-URL guard (Doc 02 FR-11, §8; owner ruling, step 13).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FR-11 preserves "the return URL" across an expired session, and the login
 * card navigates to it after sign-in — which makes this value a REDIRECT
 * TARGET on the authentication flow, the classic open-redirect position. The
 * guard therefore answers one question only: is this a same-origin relative
 * path? Anything else — however plausible — becomes `/`.
 *
 * THE REFUSAL SHAPES ARE PINNED BY OWNER RULING, because browsers normalise
 * separators in ways naive prefix checks miss:
 *
 *   https://evil.example    absolute URL — carries its own origin
 *   //evil.example          protocol-relative — inherits the scheme, not the host
 *   /\evil.example          backslash variant — Chrome and Edge treat `\` as `/`
 *                           after an origin-relative start, yielding //evil.example
 *   \/evil.example          the mirrored variant, same normalisation family
 *
 * The implementation refuses the whole CLASS, not the four examples: any
 * backslash anywhere, any second character that is a separator, anything not
 * starting with exactly one `/`. A value the guard cannot positively vouch for
 * is replaced, never "cleaned up" — rewriting attacker input is how guards
 * grow bypasses.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SafeReturnUrl
{
    public const FALLBACK = '/';

    /**
     * @param  string|null  $trustedOrigin  scheme+host(+port) of THIS app, with
     *                                      no trailing slash — the framework's
     *                                      guest redirect stores the intended
     *                                      URL absolute, so a same-origin
     *                                      absolute is reduced to its local
     *                                      path FIRST and then held to the
     *                                      same rules as any relative input
     */
    public static function sanitize(mixed $candidate, ?string $trustedOrigin = null): string
    {
        if (! is_string($candidate) || $candidate === '') {
            return self::FALLBACK;
        }

        if (is_string($trustedOrigin) && $trustedOrigin !== '' && ! str_contains($trustedOrigin, '\\')) {
            if ($candidate === $trustedOrigin) {
                return self::FALLBACK;
            }
            if (str_starts_with($candidate, $trustedOrigin.'/')) {
                // Keep the slash: the remainder starts at the path. Everything
                // below still applies to it — `https://app//evil` reduces to
                // `//evil` and is refused, not trusted for its prefix.
                $candidate = substr($candidate, strlen($trustedOrigin));
            }
        }

        // Control characters (including \r\n — header-injection material if a
        // later consumer echoes this into a Location header) void the value.
        if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return self::FALLBACK;
        }

        // Any backslash, anywhere: browsers normalise `\` to `/` inside the
        // authority-detection step, so a backslash can smuggle a host.
        if (str_contains($candidate, '\\')) {
            return self::FALLBACK;
        }

        // Exactly one leading slash: refuses absolute URLs (no leading slash),
        // protocol-relative `//host`, and triple-slash variants alike.
        if (! str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return self::FALLBACK;
        }

        return $candidate;
    }
}
