<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Run a write inside a specific tenant's context, then restore what was there.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS, AND THE BUG THAT PRODUCED IT.
 *
 * Almost everything in this platform runs with a tenant already bound: a request
 * resolves one before reaching domain code. Authentication FAILURE is the
 * exception, and it is a real one. When a login attempt fails, no tenant is ever
 * bound — resolving the tenant is what a SUCCESSFUL login does. But the failure
 * still has to be recorded against the account it targeted (FR-04's audit entry,
 * FR-03's lockout counter), and those rows live in that account's tenant, behind
 * RLS.
 *
 * Without this, the audit insert failed with:
 *
 *     SQLSTATE[22P02] invalid input syntax for type uuid: ""
 *
 * — the RLS policy comparing `tenant_id` against an unset `app.tenant_id`. Worse
 * than the error itself: it aborted the surrounding transaction, so every later
 * statement failed with "current transaction is aborted" and the ORIGINAL cause
 * was invisible behind a cascade of unrelated-looking failures.
 *
 * THE RESTORE IS THE POINT, and it is in a `finally`. Doc 01 names RlsSession the
 * most dangerous class in the project: set the session variable, fail to clear
 * it, and under Octane the next request on that connection inherits the previous
 * tenant's visibility while the Eloquent scope filters correctly — so nothing
 * looks wrong. A helper that binds a tenant to write one audit row and throws
 * before unbinding would be exactly that failure, on the login path, where the
 * request that follows belongs to somebody else.
 * ─────────────────────────────────────────────────────────────────────────────
 */
trait RunsWithinTenant
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    protected function withinTenant(
        string $tenantId,
        callable $work,
        ?TenantContext $context = null,
        ?RlsSession $rls = null,
    ) {
        $context ??= app(TenantContext::class);
        $rls ??= app(RlsSession::class);

        $previous = $context->tenantIdOrNull();

        // Already inside the target tenant: bind nothing, restore nothing. This
        // is not just an optimisation — rebinding the same tenant would clear
        // and reset the session variable mid-transaction for no reason.
        if ($previous === $tenantId) {
            return $work();
        }

        $context->clear();
        $context->bind($tenantId, ResolutionSource::StaffSession);
        $rls->set($tenantId);

        try {
            return $work();
        } finally {
            $context->clear();
            $rls->clear();

            if ($previous !== null) {
                $context->bind($previous, ResolutionSource::StaffSession);
                $rls->set($previous);
            }
        }
    }
}
