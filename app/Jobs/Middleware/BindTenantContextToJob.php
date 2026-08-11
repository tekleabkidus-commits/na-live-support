<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Closure;
use RuntimeException;

/**
 * Binding 2 of 4 — queued jobs (Doc 01 T-05, FR-03f).
 *
 * FR-03f: "Queued job → tenant id serialized into the job payload at dispatch time; a job with
 * no tenant context SHALL fail before executing its handler."
 *
 * THE ORDERING IS THE REQUIREMENT. A job that starts and then discovers it has no tenant has
 * already done work — opened a transaction, called an external API, sent a message to a player.
 * So the check is middleware, running before the handler is invoked at all, and a job dispatched
 * without a tenant fails into the failed-jobs table where it can be inspected and replayed once
 * the DISPATCHER is fixed. The dispatcher is always the bug; the job is the victim.
 *
 * Resolution rather than blind trust: this re-reads the tenant's CURRENT state, so a job queued
 * before a suspension makes the FR-05 decision against the state as it is now, not as it was at
 * dispatch. For an outbound send that difference is the whole point of suspension.
 *
 * A-35 (ruled core edit, the fifth, 2026-08-10): RESTORE-WHAT-YOU-FOUND. On a worker the prior
 * binding is nothing and the finally still ends clean — but under the sync driver a job runs
 * INLINE in the dispatcher's process, and clearing in the finally was stripping the CALLER's
 * binding (two sightings: the S07 step-6 indexer, docs/evidence/S07-step6/; the S07 step-7
 * inline bulk, docs/evidence/S07-step7/). The prior binding is captured before this middleware
 * binds its own and restored in the finally — never blind-cleared. Sabotage #22 reverts this
 * to clear-not-restore and the A-35 regression test goes red.
 */
final class BindTenantContextToJob
{
    public function handle(object $job, Closure $next): mixed
    {
        $tenantId = $job->tenantId ?? null;

        if (! is_string($tenantId) || $tenantId === '') {
            throw new RuntimeException(sprintf(
                'Job %s carries no tenant id and will not run (Doc 01 FR-03f). The tenant is captured at '
                .'DISPATCH time by the TenantAware trait — if this job was dispatched from a context with '
                .'no bound tenant, that dispatcher is the bug, not this job.',
                $job::class,
            ));
        }

        $context = app(TenantContext::class);
        $rls = app(RlsSession::class);

        $correlationId = $job->correlationId ?? null;

        $resolved = app(TenantResolver::class)
            ->resolveTrustedTenantId($tenantId, ResolutionSource::QueuedJob);

        // A-35: capture what the dispatcher had bound (null on a worker) so the
        // finally can put it back instead of clearing it away.
        $priorTenantId = $context->tenantIdOrNull();
        $priorSource = $context->source();
        $priorCorrelationId = $priorTenantId !== null ? $context->correlationId() : null;

        $context->clear();
        $context->bind(
            $resolved->tenantId,
            $resolved->source,
            is_string($correlationId) ? $correlationId : null,
        );
        $rls->set($resolved->tenantId);

        try {
            return $next($job);
        } finally {
            // A worker process handles job after job on one connection — the same reuse hazard
            // as Octane (R-01), so the same discipline. In a `finally` so a failing job cannot
            // hand its tenant to the next one. A-35: the caller's own binding, when one existed
            // (the sync driver runs jobs inline in the dispatcher's process), is RESTORED, not
            // destroyed — the job's binding is what gets cleared.
            $rls->clear();
            $context->clear();
            if ($priorTenantId !== null && $priorSource !== null) {
                $context->bind($priorTenantId, $priorSource, $priorCorrelationId);
                $rls->set($priorTenantId);
            }
        }
    }
}
