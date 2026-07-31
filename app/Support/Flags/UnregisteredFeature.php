<?php

declare(strict_types=1);

namespace App\Support\Flags;

use RuntimeException;

/**
 * A feature key that registries/features.php does not know (FR-11, ADR 0005).
 *
 * Thrown, never tolerated: an unregistered flag read that silently returned false
 * would be a flag Doc 16 cannot enumerate, cannot toggle, and cannot even see —
 * exactly the shadow state the registry exists to prevent.
 */
final class UnregisteredFeature extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf(
            'Feature "%s" is not in registries/features.php. Register it there (with its '
            .'owning doc and default) before reading it — unregistered flags cannot ship '
            .'(CLAUDE.md §2, registry #7).',
            $key,
        ));
    }
}
