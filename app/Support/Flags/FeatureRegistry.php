<?php

declare(strict_types=1);

namespace App\Support\Flags;

use App\Support\Tenancy\TenantContext;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * Registry #7 — code-registered feature flags, tenant-scoped through Pennant
 * (Doc 01 FR-11, ADR 0005, N-19).
 *
 * The registry file is the constitution; this class is its reader and enforcement
 * point. Three rules, each load-bearing:
 *
 *   1. NO UNREGISTERED READS. active() on an unknown key throws, the same posture as
 *      the settings engine — a flag that resolves without being registered is a flag
 *      Doc 16 cannot enumerate or toggle (FR-11's whole point).
 *
 *   2. THE SCOPE IS THE TENANT, ALWAYS. Pennant's default scope is the authenticated
 *      user; every resolution here goes through Feature::for(tenantId), so a flag can
 *      never leak a decision from one tenant to another and never depends on who is
 *      logged in. Reading with no tenant bound throws MissingTenantContext.
 *
 *   3. FLAGS HIDE, THEY NEVER DESTROY (N-19). Enforced structurally by the test that
 *      greps every migration for every registered key: a flag may guard rendering and
 *      routing, never schema or deletes.
 *
 * Persistence is deliberately absent — see config/pennant.php: the array store resolves
 * defaults per process, and the tenant-scoped persistent store is Doc 16's task because
 * Pennant's stock schema has no tenant_id (C-02).
 */
final class FeatureRegistry
{
    /** @var array<string, array{key: string, label_i18n_key: string, default: bool, tenant_toggleable: bool, owning_doc: string}> */
    private readonly array $entries;

    public function __construct(private readonly TenantContext $context)
    {
        $path = base_path('registries/features.php');

        if (! is_file($path)) {
            throw new RuntimeException('registries/features.php is missing — registry #7 is not optional (ADR 0005).');
        }

        /** @var list<array{key: string, label_i18n_key: string, default: bool, tenant_toggleable: bool, owning_doc: string}> $raw */
        $raw = require $path;

        $entries = [];
        foreach ($raw as $entry) {
            $entries[$entry['key']] = $entry;
        }

        $this->entries = $entries;
    }

    /**
     * Register every flag with Pennant. Called once at boot; the closure yields the
     * registered default for any scope that has no explicit decision stored.
     */
    public function defineAll(): void
    {
        foreach ($this->entries as $key => $entry) {
            Feature::define($key, static fn (mixed $scope): bool => $entry['default']);
        }
    }

    /**
     * The enumeration Doc 16 renders (FR-11): every flag, its label key, its default,
     * and whether an operator may touch it.
     *
     * @return list<array{key: string, label_i18n_key: string, default: bool, tenant_toggleable: bool, owning_doc: string}>
     */
    public function all(): array
    {
        return array_values($this->entries);
    }

    public function isRegistered(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    /**
     * Resolve a flag for the BOUND tenant.
     */
    public function active(string $key): bool
    {
        if (! $this->isRegistered($key)) {
            throw UnregisteredFeature::forKey($key);
        }

        return (bool) Feature::for($this->context->tenantId())->active($key);
    }
}
