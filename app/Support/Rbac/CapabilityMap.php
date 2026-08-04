<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * A user's resolved capabilities (Doc 03 T-02): verdict per CATALOG key plus
 * the role's per-module scope map. This is the object the cache stores
 * (verdicts only, never secrets — SEC-05) and every enforcement point
 * consumes.
 *
 * Contents are exactly the catalog's keys — an orphan DB key was dropped
 * during the build (FS-03: never enforced), and a catalog key nobody granted
 * is present as an implicit deny (FR-05's floor).
 */
final readonly class CapabilityMap
{
    /**
     * @param  array<string, Verdict>  $verdicts  keyed by permission key
     * @param  array<string, string>  $scopes  module → Own|Team|Dept|All; a
     *                                         scoped module ABSENT here fails
     *                                         closed (NFR-04) — consumers get
     *                                         null, never a default-open All
     */
    public function __construct(
        private array $verdicts,
        private array $scopes,
    ) {}

    public function has(string $key): bool
    {
        return isset($this->verdicts[$key]);
    }

    /**
     * The verdict for a catalog key. Unknown keys are a programming error at
     * the CATALOG layer (BR-03) — callers go through enforcement points that
     * validated the key already; here an unknown key is an implicit deny so a
     * stale cached map can never widen anything.
     */
    public function verdict(string $key): Verdict
    {
        return $this->verdicts[$key] ?? new Verdict(false, VerdictSource::ImplicitDeny);
    }

    public function allows(string $key): bool
    {
        return $this->verdict($key)->allowed;
    }

    /** @return list<string> allowed keys, catalog order — T-05's effective set */
    public function allowedKeys(): array
    {
        return array_keys(array_filter($this->verdicts, fn (Verdict $verdict) => $verdict->allowed));
    }

    /**
     * The role's scope level for a module, or null when the role's map is
     * silent — the caller MUST treat null as the empty set (NFR-04).
     */
    public function scopeFor(string $module): ?string
    {
        return $this->scopes[$module] ?? null;
    }

    /** @return array<string, string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /** @return array<string, Verdict> */
    public function verdicts(): array
    {
        return $this->verdicts;
    }
}
