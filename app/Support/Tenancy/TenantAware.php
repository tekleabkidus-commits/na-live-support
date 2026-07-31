<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Jobs\Middleware\BindTenantContextToJob;

/**
 * Applied to every queued job (Doc 01 T-05, FR-03f, C-14).
 *
 * Captures the tenant AT DISPATCH TIME, in the constructor, from the context bound while the
 * dispatcher runs. That timing is the requirement: at dispatch there is a tenant; by the time a
 * worker picks the job up — seconds or hours later — the request that knew is gone.
 *
 * The correlation id travels the same way, which is what makes C-14 true: "correlation_id flows
 * from the originating request through every resulting job and event". One id then traces a
 * player's message across a webhook, four jobs and three events.
 *
 * WRITTEN, DELETED, AND RESTORED. This trait and its middleware were written earlier in S1 and
 * then removed before commit, because no job existed to use them: PHPStan reports an unused
 * trait, and — more to the point — shipping an untested class that claims to implement a
 * contract is the kind of thing this project's honest-reporting rule exists to prevent. They
 * return here with ProvisionTenant, which exercises them.
 */
trait TenantAware
{
    /** Set at dispatch; read by the job middleware before the handler runs. */
    public ?string $tenantId = null;

    public ?string $correlationId = null;

    /**
     * Capture the dispatching context. Call this from the constructor.
     */
    protected function captureTenant(): void
    {
        $context = app(TenantContext::class);

        // Deliberately NOT throwing here when unbound. A job may legitimately be CONSTRUCTED
        // by a platform-wide operation (retention, reconciliation) that then dispatches it per
        // tenant. The hard failure belongs at execution time, where FR-03f puts it: "a job with
        // no tenant context SHALL fail before executing its handler".
        $this->tenantId = $context->tenantIdOrNull();
        $this->correlationId = $context->isBound() ? $context->correlationId() : null;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContextToJob];
    }
}
