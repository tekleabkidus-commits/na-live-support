<?php

declare(strict_types=1);

namespace App\Domain\Automation\Webhooks;

/**
 * Doc 12 SEC-02 — "no internal-network targets (SSRF egress rules, Doc 04
 * SEC-03 family)". PURE: a destination URL is admitted only when it is
 * https, names a real public hostname (no user-info, no IP literal of any
 * kind — literals bypass every DNS-based allowlist — no single-label
 * service name like `postgres`, no localhost/.local/.internal/.lan
 * family). Checked at destination BIRTH (the url column is then immutable
 * by grant) AND again before every send (defense in depth, cheap). The
 * egress PROXY is the second layer: it holds its own host allowlist and dies
 * 403 on anything else — this class keeps the obvious attacks from ever
 * reaching it.
 */
final class SsrfRules
{
    public const CODE = 'ssrf_refused';

    private const DENIED_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home', '.corp', '.intranet'];

    /** @throws WebhookDestinationRefused */
    public static function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || $parts['host'] === '') {
            throw new WebhookDestinationRefused('a webhook destination must be an https URL with a hostname (SEC-02)');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new WebhookDestinationRefused('a webhook destination must not carry user-info (SEC-02)');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new WebhookDestinationRefused(sprintf('"%s" is a loopback or IP-literal target — refused (SEC-02 SSRF rules)', $host));
        }
        if (! str_contains($host, '.')) {
            throw new WebhookDestinationRefused(sprintf('"%s" is a single-label (internal service) name — refused (SEC-02 SSRF rules)', $host));
        }
        foreach (self::DENIED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new WebhookDestinationRefused(sprintf('"%s" names an internal-network zone — refused (SEC-02 SSRF rules)', $host));
            }
        }
    }

    public static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}
