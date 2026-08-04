<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use LogicException;

/**
 * The typed reader of the permission catalog (Doc 03 FR-01, §4.1; registry #1).
 *
 * `registries/permissions.php` stays the single machine-readable source of
 * truth (same posture as PermissionRegistry, which answers only "is this key
 * registered"); this class is the catalog the RESOLVER and the admin screens
 * consume: module, risk level and scope dimension per key. The catalog is
 * CLOSED (BR-03): asking about an unregistered key is a programming error,
 * loudly — never a silent deny, which would mask a typo'd check as a verdict.
 *
 * NFR-03: keys are never renamed or repurposed. CatalogSnapshotTest (T-05b)
 * pins the exact key set, the 38 "(high)" marks and the 46 scoped keys, so an
 * accidental edit to the registry fails CI before it can ship.
 */
final class PermissionCatalog
{
    /** @var array<string, PermissionDefinition>|null */
    private ?array $definitions = null;

    /**
     * @return array<string, PermissionDefinition> keyed by permission key, registry order
     */
    public function all(): array
    {
        if ($this->definitions === null) {
            /** @var list<array{key: string, module: string, label_i18n_key: string, description_i18n_key: string, risk: string, scope_dimension: string}> $entries */
            $entries = require base_path('registries/permissions.php');

            $definitions = [];
            foreach ($entries as $entry) {
                $definitions[$entry['key']] = new PermissionDefinition(
                    key: $entry['key'],
                    module: $entry['module'],
                    labelI18nKey: $entry['label_i18n_key'],
                    descriptionI18nKey: $entry['description_i18n_key'],
                    high: $entry['risk'] === 'high',
                    scoped: $entry['scope_dimension'] === 'scoped',
                );
            }
            $this->definitions = $definitions;
        }

        return $this->definitions;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): PermissionDefinition
    {
        return $this->all()[$key] ?? throw new LogicException(sprintf(
            'Permission key "%s" is not in the catalog (registries/permissions.php). The catalog is '
            .'closed (Doc 03 BR-03) — register the key or fix the typo; never treat an unknown key as a verdict.',
            $key,
        ));
    }

    public function isHigh(string $key): bool
    {
        return $this->get($key)->high;
    }

    public function isScoped(string $key): bool
    {
        return $this->get($key)->scoped;
    }

    /**
     * @return list<string> keys marked "(high)" in §4.1, registry order
     */
    public function highKeys(): array
    {
        return array_keys(array_filter($this->all(), fn (PermissionDefinition $d) => $d->high));
    }

    /**
     * @return array<string, list<string>> module id → its keys, registry order
     */
    public function byModule(): array
    {
        $grouped = [];
        foreach ($this->all() as $definition) {
            $grouped[$definition->module][] = $definition->key;
        }

        return $grouped;
    }
}
