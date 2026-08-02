<?php

declare(strict_types=1);

namespace App\Domain\Auth\Widget;

use Carbon\CarbonImmutable;

/**
 * One live visitor session as stored in Redis (Doc 02 FR-21/FR-22).
 *
 * `visitorId` is the durable identity ("history preserved server-side via
 * visitor_id, distinct from the token") — Doc 04/08 key conversations and shell
 * customers to it. The token and cookie hashes are the CREDENTIALS for that
 * identity, and both are stored as SHA-256 only: the plaintexts exist in the
 * visitor's browser and nowhere else, the same posture SEC-03 takes for every
 * other secret at rest.
 */
final readonly class VisitorRecord
{
    public function __construct(
        public string $visitorId,
        public string $widgetId,
        public string $cookieHash,
        public ?string $previousCookieHash,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $lastSeenAt,
    ) {}

    /**
     * FR-22's pairing check: the presented cookie must match the current
     * secret, or — one rotation of grace for parallel tabs and in-flight
     * requests (step-10 ruling, decision 2) — the immediately previous one.
     */
    public function cookieMatches(string $presented): bool
    {
        $presentedHash = hash('sha256', $presented);

        if (hash_equals($this->cookieHash, $presentedHash)) {
            return true;
        }

        return $this->previousCookieHash !== null
            && hash_equals($this->previousCookieHash, $presentedHash);
    }
}
