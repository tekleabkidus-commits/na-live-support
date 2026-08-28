<?php

declare(strict_types=1);

namespace App\Domain\Kb\Tombstones;

/**
 * Doc 10 FR-16 / EC-07 / SS-04 — what ONE lookup of a version-pinned citation yields:
 * the state, the exact version the customer saw (title + snapshot id), and the current
 * version's URL when one exists. Never a 404 — Unknown is a tombstone without a link.
 */
final class CitationFacts
{
    /** @param array<string, mixed>|null $shown */
    public function __construct(
        public readonly CitationState $state,
        public readonly string $articleId,
        public readonly string $versionId,
        public readonly ?array $shown,
        public readonly ?string $currentUrl,
        public readonly ?string $currentVersionId,
        public readonly string $tombstoneKey,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['state' => $this->state->value, 'article_id' => $this->articleId, 'version_id' => $this->versionId, 'shown' => $this->shown, 'current_url' => $this->currentUrl, 'current_version_id' => $this->currentVersionId, 'tombstone_key' => $this->tombstoneKey];
    }
}
