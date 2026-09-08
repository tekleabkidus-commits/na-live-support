<?php

declare(strict_types=1);

namespace App\Domain\Sms\Rendering;

use App\Support\Settings\SettingsRepository;

/**
 * Doc 14 SEC-05 / AC-10 — {{link}} resolves ONLY to a platform-generated short
 * URL on the tenant's configured domain (`sms.link.domain`). The short link
 * is STATELESS and SIGNED: `https://<domain>/s/<kind>.<id>.<hmac12>` — no
 * table, nothing an operator can point elsewhere, nothing that can carry a
 * foreign host. The landing surface is the target's own document (Doc 10's
 * reader today; Doc 06 §19's status page when it exists) — resolving to a
 * target that does not exist yet answers null, and the template's on_missing
 * policy decides (FR-06).
 */
final class LinkResolver
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function resolve(?LinkTarget $target): ?string
    {
        if ($target === null) {
            return null;
        }
        $domain = $this->settings->get('sms.link.domain');
        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }
        if ($target->kind === 'ticket_status') {
            return null; // Doc 06 §19: not built — never a fabricated URL
        }
        $payload = $target->kind.'.'.$target->id;
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new \RuntimeException('app.key is required to sign short links');
        }
        $signature = substr(hash_hmac('sha256', $payload, $key), 0, 12);

        return sprintf('https://%s/s/%s.%s', trim($domain), $payload, $signature);
    }
}
