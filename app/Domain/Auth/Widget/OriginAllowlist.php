<?php

declare(strict_types=1);

namespace App\Domain\Auth\Widget;

/**
 * The ONE matcher for widget origin allowlists (Doc 02 FR-21, AC-06, EC-08;
 * step-10 ruling, decision 5).
 *
 * Semantics, pinned by owner ruling and by fixture:
 *
 *   https only          An http:// origin never matches anything. SEC-06's
 *                       posture applied to the one surface that lives on other
 *                       people's pages.
 *   host case-normalized DNS names are case-insensitive; "HELP.Brand.com" and
 *                       "help.brand.com" are the same host. Schemes arrive
 *                       lowercase from browsers but are normalized anyway.
 *   ports exact         An entry without a port means 443 (the https default),
 *                       and a presented origin without a port means 443. Any
 *                       explicit port must match exactly — "https://a.example"
 *                       does not admit "https://a.example:8443".
 *   exact entries       "https://help.brand.com" or "https://brand.com:8443" —
 *                       full origins, matched whole.
 *   wildcard entries    "*.brand.com" (optional ":port") matches any host that
 *                       ends in ".brand.com" — one or many labels deep — and
 *                       NEVER the bare apex "brand.com". The DOT BOUNDARY is
 *                       load-bearing: "evil-brand.com" ends in "brand.com" but
 *                       not in ".brand.com", so it does not match. That hostile
 *                       case (Doc 13 EC-12) is a mandatory fixture; weakening
 *                       this comparison to a bare suffix must turn it red.
 *
 * What is deliberately NOT here (same ruling): punycode/homograph handling. No
 * document asks for it, and inventing it would be scope creep — an operator who
 * allowlists a domain is responsible for the domains they allowlist, lookalikes
 * included. Comparison is byte-wise on the normalized ASCII form the browser
 * presents.
 */
final class OriginAllowlist
{
    private const HTTPS_DEFAULT_PORT = 443;

    /**
     * @param  list<string>  $entries  the widget's allowed_origins, verbatim
     */
    public function matches(string $origin, array $entries): bool
    {
        $presented = $this->parseOrigin($origin);

        if ($presented === null) {
            return false;
        }

        [$host, $port] = $presented;

        foreach ($entries as $entry) {
            if ($this->entryMatches($entry, $host, $port)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every declared ancestor must itself be allowlisted (EC-08).
     *
     * An empty list is a top-level page — allowed. The moment ANY frame in the
     * chain is off-list, issuance is refused: a disallowed page wrapping an
     * allowed one is exactly the attack EC-08 names.
     *
     * @param  list<string>  $ancestors
     * @param  list<string>  $entries
     */
    public function allowsAncestry(array $ancestors, array $entries): bool
    {
        foreach ($ancestors as $ancestor) {
            if (! $this->matches($ancestor, $entries)) {
                return false;
            }
        }

        return true;
    }

    private function entryMatches(string $entry, string $host, int $port): bool
    {
        $entry = strtolower(trim($entry));

        if ($entry === '') {
            return false;
        }

        if (str_starts_with($entry, '*.')) {
            [$entryHost, $entryPort] = $this->splitHostPort(substr($entry, 2));

            if ($entryHost === null || $entryPort !== $port) {
                return false;
            }

            // The dot boundary: the presented host must end in ".{entryHost}",
            // dot included. `str_ends_with($host, $entryHost)` alone would admit
            // "evil-brand.com" for "*.brand.com" — sabotage (c)'s exact hole.
            return str_ends_with($host, '.'.$entryHost) && $host !== $entryHost;
        }

        $parsed = $this->parseOrigin($entry);

        if ($parsed === null) {
            return false;
        }

        [$entryHost, $entryPort] = $parsed;

        return $entryHost === $host && $entryPort === $port;
    }

    /**
     * @return array{0: string, 1: int}|null [lowercased host, resolved port]
     */
    private function parseOrigin(string $origin): ?array
    {
        $origin = strtolower(trim($origin));

        if (! str_starts_with($origin, 'https://')) {
            return null;
        }

        $rest = substr($origin, strlen('https://'));

        // An origin is scheme://host[:port] — a path, query, userinfo or
        // fragment means the caller sent a URL, not an origin. Refused rather
        // than leniently truncated: lenient parsing of security identifiers is
        // how "https://brand.com.evil.example/brand.com" classes of confusion
        // start.
        if ($rest === '' || strpbrk($rest, '/?#@ ') !== false) {
            return null;
        }

        [$host, $port] = $this->splitHostPort($rest);

        if ($host === null) {
            return null;
        }

        return [$host, $port];
    }

    /**
     * @return array{0: string|null, 1: int} [host or null when malformed, port]
     */
    private function splitHostPort(string $hostPort): array
    {
        if (! str_contains($hostPort, ':')) {
            return [$hostPort === '' ? null : $hostPort, self::HTTPS_DEFAULT_PORT];
        }

        [$host, $portPart] = explode(':', $hostPort, 2);

        if ($host === '' || $portPart === '' || preg_match('/^\d{1,5}$/', $portPart) !== 1) {
            return [null, self::HTTPS_DEFAULT_PORT];
        }

        $port = (int) $portPart;

        if ($port < 1 || $port > 65535) {
            return [null, self::HTTPS_DEFAULT_PORT];
        }

        return [$host, $port];
    }
}
