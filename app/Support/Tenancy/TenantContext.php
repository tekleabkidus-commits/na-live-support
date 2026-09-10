<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Support\Str;

/**
 * The one place that knows which tenant we are serving (Doc 01 T-05).
 *
 * Registered as a SCOPED container binding, not a singleton. Under Octane the
 * container survives between requests, so a singleton would carry tenant A's id
 * into tenant B's request — the R-01 risk, in the application layer rather than
 * the database. `scoped()` bindings are flushed at Octane's request boundary,
 * which makes leakage the default-impossible case rather than something a
 * listener has to remember to undo. TenancyServiceProvider additionally clears
 * this and the database session variable explicitly, because two independent
 * mechanisms is the standing pattern in this module.
 *
 * WHY BINDING IS IRREVERSIBLE WITHIN A SCOPE. `bind()` refuses to overwrite an
 * already-bound tenant. FR-03 says resolution happens "exactly once per
 * request/job/socket connection" and that word once is load-bearing: a second
 * bind would mean either a resolution bug or an attempt to switch tenants
 * mid-request, and both must be a crash rather than a silent reassignment.
 * Deliberate tenant switching — provisioning several tenants in one command,
 * tests — goes through `runFor()`, which restores the previous state afterwards.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private ?ResolutionSource $source = null;

    private ?string $correlationId = null;

    /**
     * True while a platform-wide operation is running (SEC-02).
     *
     * Only PlatformConnection::run() sets this, and it refuses to run at all if a
     * tenant is bound. It is what lets the platform console read across tenants
     * without giving tenant-scoped code any way to do the same.
     */
    private bool $platformOperation = false;

    public function bind(string $tenantId, ResolutionSource $source, ?string $correlationId = null): void
    {
        if ($this->tenantId !== null && $this->tenantId !== $tenantId) {
            throw new \LogicException(sprintf(
                'Tenant context is already bound to %s and cannot be rebound to %s (Doc 01 FR-03: '
                .'resolution happens exactly once). Use TenantContext::runFor() for a deliberate, '
                .'scoped switch.',
                $this->tenantId,
                $tenantId,
            ));
        }

        $this->tenantId = $tenantId;
        $this->source = $source;

        // C-14: a correlation id always exists from here on, so every log line,
        // job and event downstream can carry it. Generated only if the entry
        // point did not supply one.
        $this->correlationId = $correlationId ?? (string) Str::uuid7();
    }

    public function tenantId(): string
    {
        if ($this->tenantId === null) {
            throw MissingTenantContext::forRead();
        }

        return $this->tenantId;
    }

    public function tenantIdOrNull(): ?string
    {
        return $this->tenantId;
    }

    public function isBound(): bool
    {
        return $this->tenantId !== null;
    }

    public function source(): ?ResolutionSource
    {
        return $this->source;
    }

    /**
     * The id that ties a request to every job and event it causes (C-14).
     *
     * Available even with no tenant bound — a request that fails resolution still
     * needs to be traceable, and AC-02 proves forged input had no effect by
     * following exactly this id.
     */
    public function correlationId(): string
    {
        return $this->correlationId ??= (string) Str::uuid7();
    }

    public function clear(): void
    {
        // P-SCRUB-LIVE (Doc 14, countersigned): the request/job's revealed secrets die with its tenant binding.
        \App\Support\Secrets\LiveSecretValues::clear();
        $this->tenantId = null;
        $this->source = null;
        $this->correlationId = null;
        $this->platformOperation = false;
    }

    public function isPlatformOperation(): bool
    {
        return $this->platformOperation;
    }

    /**
     * @internal Only PlatformConnection may call this.
     */
    public function markPlatformOperation(bool $running): void
    {
        $this->platformOperation = $running;
    }

    /**
     * Run a callback bound to a specific tenant, restoring the prior state after.
     *
     * The legitimate way to touch more than one tenant in one process: a console
     * command provisioning several tenants, a reconciliation job walking every
     * tenant, a test arranging two tenants' data. The restore is in a `finally`
     * so an exception cannot leave the wrong tenant bound for whatever runs next.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runFor(string $tenantId, ResolutionSource $source, callable $callback): mixed
    {
        $previousTenant = $this->tenantId;
        $previousSource = $this->source;
        $previousCorrelation = $this->correlationId;

        $this->tenantId = $tenantId;
        $this->source = $source;
        $this->correlationId ??= (string) Str::uuid7();

        try {
            return $callback();
        } finally {
            $this->tenantId = $previousTenant;
            $this->source = $previousSource;
            $this->correlationId = $previousCorrelation;
        }
    }
}
