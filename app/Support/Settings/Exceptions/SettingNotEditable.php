<?php

declare(strict_types=1);

namespace App\Support\Settings\Exceptions;

use RuntimeException;

/**
 * A tenant-facing path tried to write a platform-only setting (Doc 01 FR-07).
 *
 * `tenant_editable: false` marks settings the platform owns — BR-05's 30-day cold-summary
 * cutoff, quota ceilings, anything an operator must not be able to raise for themselves.
 * They are still READABLE by tenant code, because they configure its behaviour; they are
 * simply not writable through any operator surface.
 *
 * Thrown rather than silently ignored: an operator who submits a change and sees no error
 * will reasonably believe it took effect.
 */
final class SettingNotEditable extends RuntimeException
{
    public static function forKey(string $key, string $owningDoc): self
    {
        return new self(sprintf(
            'Setting "%s" is platform-owned (tenant_editable: false, owned by %s) and cannot be written '
            .'through a tenant surface (Doc 01 FR-07). If an operator genuinely should control this, that '
            .'is a registry change with the owning document\'s sign-off, not a caller-side override.',
            $key,
            $owningDoc,
        ));
    }
}
