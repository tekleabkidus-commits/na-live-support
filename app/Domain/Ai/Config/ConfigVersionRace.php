<?php

declare(strict_types=1);

namespace App\Domain\Ai\Config;

use RuntimeException;

/**
 * The RaceLost-sentinel for ai_config_versions writes (SCHEMA AMENDMENT 3
 * flag 2's countersign condition; the S08 RaceLost precedent — the platform
 * note: a DB::transaction closure's abandon path must THROW).
 *
 * A concurrent draft (identity index) or concurrent publish (the partial
 * published arbiter) surfaces as THIS final exception, caught one frame
 * outside by the caller — never a raw 23505 reaching a door. No ON CONFLICT
 * statement ever targets ai_config_versions (asserted statically in
 * AiGrantPhysicsTest); serialization is transaction + sentinel, by design.
 */
final class ConfigVersionRace extends RuntimeException
{
    public static function onDraft(string $kind, string $key): self
    {
        return new self(sprintf(
            'a concurrent draft claimed the next version for %s/%s — re-read and retry (FR-04)',
            $kind,
            $key,
        ));
    }

    public static function onPublish(string $versionId): self
    {
        return new self(sprintf(
            'a concurrent publish won the %s slot for version %s — re-read the active version before retrying (FR-04)',
            'published',
            $versionId,
        ));
    }
}
