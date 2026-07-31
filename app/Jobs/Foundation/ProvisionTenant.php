<?php

declare(strict_types=1);

namespace App\Jobs\Foundation;

use App\Domain\Foundation\Provisioning\TenantProvisioner;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * FR-06's idempotent provisioning job (Doc 01 FR-06, EC-04, EC-05, AC-05, SS-01).
 *
 * SS-01 targets end-to-end provisioning in ≤2 minutes, which is why this is a job rather than
 * inline work in the create-tenant request: the six steps include seams that will eventually
 * seed a knowledge base, and §8 shows a progress screen precisely because the operator is not
 * meant to wait on an HTTP response.
 *
 * IT DOES NOT USE TenantAware's DISPATCH-TIME CAPTURE FOR ITS OWN TENANT, and the distinction
 * matters. The tenant does not exist yet when this job is dispatched — the platform console
 * generates the id and hands it over. So the id is a constructor argument, and `tenantId` is
 * set from it so the job middleware binds the right context. Every OTHER job in the platform
 * will capture an already-bound tenant instead; this one is the exception because it is the
 * thing that creates tenants.
 *
 * Retries are safe by construction (EC-05): each step is claimed by a unique index, so a retry
 * skips completed steps and picks up where it stopped. `$tries` is therefore generous — there
 * is no partial-write risk to protect against.
 */
final class ProvisionTenant implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $newTenantId,
        public readonly array $attributes,
    ) {
        // The tenant this job operates on is the one it is about to create, so the job
        // middleware's binding target is the NEW id rather than a captured one.
        $this->tenantId = $newTenantId;

        // Correlation still comes from the dispatching context (C-14), so the console request
        // that asked for a tenant can be traced through to the events provisioning publishes.
        $this->captureTenant();
        $this->tenantId = $newTenantId;
    }

    /**
     * NO TENANT-BINDING MIDDLEWARE, and this is the one job in the platform for which that is
     * correct.
     *
     * TenantAware supplies BindTenantContextToJob by default, and that middleware resolves the
     * tenant — which means reading its state, so that a job queued before a suspension makes the
     * FR-05 decision against current reality. Every other job wants that.
     *
     * This job cannot have it: the tenant DOES NOT EXIST YET. Resolution correctly returns
     * TenantNotResolved (404 semantics, SEC-06), and the job dies before creating the thing it
     * exists to create. Found by dispatching it.
     *
     * So the binding happens inside TenantProvisioner::withTenantBound() instead, which binds
     * the generated id before the first write — the same generate-then-bind-then-write sequence
     * that lets provisioning run without an RLS bypass. The trait is still used, for the
     * correlation id (C-14) and the tenantId payload; only the middleware is declined.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [];
    }

    public function handle(TenantProvisioner $provisioner): void
    {
        $provisioner->provision($this->attributes, $this->newTenantId);
    }
}
