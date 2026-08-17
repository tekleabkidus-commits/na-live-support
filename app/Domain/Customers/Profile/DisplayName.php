<?php

declare(strict_types=1);

namespace App\Domain\Customers\Profile;

/**
 * FR-01's display derivation, as ONE brain (S08 step 12; the filed reading
 * in IdentityResolution's docblock): staff-set name → channel identity
 * display → username → null (the renderer's "Customer #short-id" fallback).
 * The staff slot is `customers.display_name`; channel provenance lives in
 * identity metadata — this helper is where the chain is walked, so the
 * directory table, the search documents and any future surface cannot
 * derive three different names for one human.
 */
final class DisplayName
{
    /**
     * @param  list<array{display: ?string, username: ?string}>  $identityFacts  channel facts in first-seen order
     */
    public static function derive(?string $staffName, array $identityFacts): ?string
    {
        if (is_string($staffName) && $staffName !== '') {
            return $staffName;
        }
        foreach ($identityFacts as $facts) {
            if (is_string($facts['display']) && $facts['display'] !== '') {
                return $facts['display'];
            }
        }
        foreach ($identityFacts as $facts) {
            if (is_string($facts['username']) && $facts['username'] !== '') {
                return '@'.ltrim($facts['username'], '@');
            }
        }

        return null;
    }

    /**
     * The metadata slice derive() needs, from raw identity metadata JSON.
     *
     * @return array{display: ?string, username: ?string}
     */
    public static function factsFromMetadata(?string $metadataJson): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = $metadataJson !== null ? (array) json_decode($metadataJson, true) : [];

        return [
            'display' => is_string($metadata['display'] ?? null) ? $metadata['display'] : null,
            'username' => is_string($metadata['username'] ?? null) ? $metadata['username'] : null,
        ];
    }
}
