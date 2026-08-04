<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Support\Cache\TenantCache;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FR-11: every API permission-check failure is LOGGED (actor, permission,
 * object type/id, trace id) and COUNTED per tenant — the security metric
 * US-05's monthly report and R-02's denied-spike review read. Wired here in
 * S19; RequirePermission calls it the moment the enforce flip lands (step 7),
 * and every later doc's routes inherit it for free.
 *
 * The counter lives in the tenant cache (C-07: prefixed, mandatory TTL) in
 * daily buckets kept 14 days — Doc 15 aggregates into durable reporting; this
 * counter is the live signal, not the archive. Counting must never break the
 * deny itself: failures degrade to the log line alone.
 */
final class DeniedCheckRecorder
{
    private const BUCKET_TTL_SECONDS = 14 * 86400;

    public function __construct(
        private readonly TenantCache $cache,
        private readonly TenantContext $context,
    ) {}

    public function record(
        string $permission,
        VerdictSource $source,
        ?string $actorId,
        ?string $objectType = null,
        ?string $objectId = null,
    ): void {
        Log::info('rbac.check_denied', [
            'permission' => $permission,
            'verdict_source' => $source->value,
            'actor_id' => $actorId,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'trace_id' => $this->context->correlationId(),
            'security_metric' => 'rbac.denied_checks',
        ]);

        try {
            $this->cache->increment(
                'metrics:rbac_denied:'.gmdate('Y-m-d'),
                1,
                self::BUCKET_TTL_SECONDS,
                'TTL only — a metric bucket, aggregated by Doc 15',
            );
        } catch (Throwable) {
            // The log line above already carries the named metric; a cache
            // outage must not turn a deny into an error (FS-01 posture).
        }
    }
}
