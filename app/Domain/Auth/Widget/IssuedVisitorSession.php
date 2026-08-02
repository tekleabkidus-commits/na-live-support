<?php

declare(strict_types=1);

namespace App\Domain\Auth\Widget;

/**
 * What issuance hands back — the ONLY moment either plaintext exists
 * server-side (Doc 02 FR-21/FR-22).
 *
 * Both credentials go to the visitor's browser (token to the widget script,
 * cookie via Set-Cookie) and are stored hashed; there is no "reveal" path
 * later, exactly like every other credential in this module.
 */
final readonly class IssuedVisitorSession
{
    public function __construct(
        public string $visitorId,
        public string $token,
        public string $cookie,
    ) {}
}
