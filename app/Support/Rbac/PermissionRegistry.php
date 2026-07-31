<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use LogicException;

/**
 * The permission registry's code-side reader (CLAUDE.md §2, registry #1).
 *
 * SCOPE — READ CAREFULLY BEFORE EXTENDING. Doc 03 owns roles, grants, scopes,
 * overrides, and the effective-permissions resolver. This class is only the part
 * Doc 02 needs today: "is this key registered at all?", so a route declaring
 * `permission:<key>` with a typo'd key fails a test instead of silently declaring
 * a permission nobody can ever hold. Doc 03 builds its resolver beside this,
 * consuming the same file; it does not grow verdict logic here.
 *
 * Same posture as the settings registry: the file in /registries is the single
 * source of truth, and an unregistered key is a programming error, loudly.
 */
final class PermissionRegistry
{
    /** @var array<string, true>|null */
    private ?array $keys = null;

    /**
     * @return array<string, true>
     */
    public function keys(): array
    {
        if ($this->keys === null) {
            /** @var list<array{key: string}> $entries */
            $entries = require base_path('registries/permissions.php');
            $this->keys = array_fill_keys(array_column($entries, 'key'), true);
        }

        return $this->keys;
    }

    public function isRegistered(string $key): bool
    {
        return isset($this->keys()[$key]);
    }

    public function assertRegistered(string $key): void
    {
        if (! $this->isRegistered($key)) {
            throw new LogicException(sprintf(
                'Permission key "%s" is not registered in registries/permissions.php. A route may only '
                .'declare registered keys (CLAUDE.md §2) — register the key or fix the typo.',
                $key,
            ));
        }
    }
}
