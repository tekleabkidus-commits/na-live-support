<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * T-09: the k-anonymity breached-password check. 500 ms, fail-open, metric.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT LEAVES THE BUILDING IS FIVE HEX CHARACTERS. The candidate password is
 * SHA-1'd locally and only the first 5 characters of the digest are sent — a
 * bucket of ~800 unrelated suffixes comes back, and the membership test happens
 * HERE. The remote service cannot learn the password, cannot learn the full
 * hash, and cannot even learn whether the answer was "breached": it sees one
 * range query indistinguishable from any other. BreachCheckTest asserts the
 * outbound request contains exactly the prefix and never more.
 *
 * FAIL-OPEN IS THE SPECIFIED POSTURE, and it is worth saying why it is safe
 * here when fail-open is usually the bug: this check is an ENRICHMENT on top of
 * a policy that already enforces length and history (NFR-04's category). A
 * password rejected only while a third-party API is up would make account
 * recovery — the one path that must always work, EC-11 — depend on someone
 * else's uptime. FS-03: skipped with a metric, never blocking. The inversion
 * (refusing passwords during an outage) is what BreachCheckTest's sabotage
 * clause exists to catch.
 *
 * SEC-08: nothing derived from the password is logged — not the prefix, not
 * the suffix, not the digest. The metric line carries only the failure class.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class BreachChecker
{
    public const METRIC = 'auth.breach_check.failed';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * True only on a POSITIVE match from a healthy response. Every failure
     * mode — no endpoint, timeout, non-2xx, garbage body — answers false.
     */
    public function isBreached(string $plaintext): bool
    {
        $base = config('services.breach_check.base_url');

        if (! is_string($base) || $base === '') {
            $this->metric('unconfigured');

            return false;
        }

        $digest = strtoupper(sha1($plaintext));
        $prefix = substr($digest, 0, 5);
        $suffix = substr($digest, 5);

        $timeout = config('services.breach_check.timeout_seconds');
        $seconds = is_numeric($timeout) ? (float) $timeout : 0.5;

        try {
            $response = $this->http
                ->timeout($seconds)
                ->connectTimeout($seconds)
                ->get(rtrim($base, '/').'/range/'.$prefix);
        } catch (\Throwable) {
            $this->metric('unreachable');

            return false;
        }

        if (! $response->successful()) {
            $this->metric('http_'.$response->status());

            return false;
        }

        // One "SUFFIX:COUNT" per line. A count of 0 exists in padded responses
        // and means NOT breached — the padding entries are decoys.
        foreach (preg_split('/\r\n|\n/', $response->body()) ?: [] as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');

            if (hash_equals($suffix, strtoupper($candidate)) && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * NFR-04's named metric, R-02's answer to fail-open masking an outage:
     * every skipped check says so, with its failure class, in the platform log
     * where Doc 18's monitoring reads. Never anything password-derived (SEC-08).
     */
    private function metric(string $failureClass): void
    {
        logger()->warning(self::METRIC, ['failure' => $failureClass]);
    }
}
