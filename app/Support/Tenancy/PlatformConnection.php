<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The only door to the RLS-bypassing role (Doc 01 SEC-02).
 *
 * SEC-02 has three clauses and this class implements all three:
 *
 *   "used only by the platform console service account and retention jobs"
 *       → callers must pass a PlatformActor naming which of those they are.
 *   "its use is logged with actor identity"
 *       → every entry and exit is logged, with the actor and the reason.
 *   "application tenant traffic can never reach it"
 *       → run() REFUSES to start if a tenant context is bound. That is the whole
 *         defence, and it is worth explaining why it is enough. Tenant traffic is
 *         defined by having resolved a tenant; every one of the six resolution
 *         paths binds a context before any domain code runs. So "no tenant is
 *         bound" is not a proxy for "not tenant traffic" — it is the same
 *         statement. A request that somehow reached here without resolving would
 *         also have no data to steal, because it could not have been routed to a
 *         tenant's records in the first place.
 *
 * The connection name is `pgsql_platform`; see config/database.php. Nothing else
 * in the codebase may name that connection — tests/Security asserts it.
 */
final class PlatformConnection
{
    public const NAME = 'pgsql_platform';

    /**
     * Run a platform-wide operation with RLS bypassed.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(PlatformActor $actor, string $reason, Closure $callback): mixed
    {
        $context = app(TenantContext::class);

        if ($context->isBound()) {
            throw new LogicException(sprintf(
                'PlatformConnection::run() was called with tenant %s bound (Doc 01 SEC-02: application '
                .'tenant traffic can never reach the platform_admin role). A cross-tenant read from '
                .'inside a tenant request is never legitimate — if this is a platform operation, it does '
                .'not belong on a tenant-resolved code path.',
                $context->tenantId(),
            ));
        }

        // SEC-02's logging clause. At INFO, not DEBUG: bypassing tenant isolation
        // is a notable event even when entirely routine, and an auditor asking
        // "who read across tenants last month" must get an answer from logs.
        Log::info('platform_admin connection opened', [
            'actor' => $actor->value,
            'reason' => $reason,
            'correlation_id' => $context->correlationId(),
        ]);

        $context->markPlatformOperation(true);

        try {
            return $callback();
        } finally {
            $context->markPlatformOperation(false);

            Log::info('platform_admin connection closed', [
                'actor' => $actor->value,
                'reason' => $reason,
                'correlation_id' => $context->correlationId(),
            ]);
        }
    }
}
