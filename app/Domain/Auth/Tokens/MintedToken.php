<?php

declare(strict_types=1);

namespace App\Domain\Auth\Tokens;

use App\Models\PersonalAccessToken;

/**
 * A freshly minted token and the ONE existence of its plaintext (FR-18).
 *
 * The plaintext lives exactly as long as the response that shows it once.
 * Nothing persists it — the row stores the SHA-256 — and nothing may log it
 * (SEC-08's scrubber gate seeds fake credentials to prove that class of leak).
 */
final readonly class MintedToken
{
    public function __construct(
        public PersonalAccessToken $token,
        public string $plaintext,
    ) {}
}
