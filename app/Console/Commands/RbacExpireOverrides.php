<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UserPermissionOverride;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityCacheInvalidator;
use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FR-09's cleanup half — and ONLY that half. Expiry is enforced by the
 * resolver's DB-time check on every read (EC-09); by the time this pass
 * touches a row, every check anywhere has been denying against it already.
 * This tidies: marks the row revoked(cause=expired), emits
 * rbac.override.expired through the outbox and writes the audit entry
 * (AC-07's trailing clauses). Nothing about SECURITY depends on the schedule
 * — delete this command and verdicts do not change (that is the FR-09 test).
 *
 * Cross-tenant discovery runs on the platform connection like every walking
 * job (SEC-02, logged actor); each tenant's rows are then processed INSIDE
 * that tenant's context so the outbox row, audit entry and RLS all land in
 * the right place. Idempotent by shape: a row is eligible only while
 * revoked_at IS NULL, and the guarded UPDATE re-checks under the row lock.
 */
final class RbacExpireOverrides extends Command
{
    protected $signature = 'rbac:expire-overrides';

    protected $description = 'Tidy expired permission overrides: revoke(cause=expired), emit rbac.override.expired, audit (Doc 03 FR-09/AC-07)';

    public function handle(
        TenantContext $context,
        EventPublisher $events,
        AuditLogger $audit,
        CapabilityCacheInvalidator $invalidator,
    ): int {
        /** @var list<object{tenant_id: string}> $tenants */
        $tenants = PlatformConnection::run(
            PlatformActor::RetentionJob,
            'FR-09 override-expiry tidy: find tenants with expired live overrides',
            static fn (): array => DB::connection(PlatformConnection::NAME)->select(
                'SELECT DISTINCT tenant_id FROM user_permission_overrides WHERE revoked_at IS NULL AND expires_at <= now()
                 UNION SELECT DISTINCT tenant_id FROM pending_grants WHERE status = \'pending\' AND expires_at <= now()',
            ),
        );

        $tidied = 0;
        foreach ($tenants as $tenantRow) {
            $tidied += $context->runFor(
                $tenantRow->tenant_id,
                ResolutionSource::Console,
                function () use ($tenantRow, $events, $audit, $invalidator): int {
                    app(\App\Support\Tenancy\RlsSession::class)->set($tenantRow->tenant_id);

                    try {
                        // T-06: pending high-risk grants auto-expire 24 h — a
                        // STATUS flip only (the resolver ignored them all
                        // along; the index endpoint's DB-time filter already
                        // hid them). FR-12 names no event for it.
                        \App\Models\PendingGrant::query()
                            ->where('status', 'pending')
                            ->whereRaw('expires_at <= now()')
                            ->update(['status' => 'expired']);

                        return $this->tidyBoundTenant($events, $audit, $invalidator);
                    } finally {
                        app(\App\Support\Tenancy\RlsSession::class)->clear();
                    }
                },
            );
        }

        Log::info('expired permission overrides tidied', [
            'tidied' => $tidied,
            'metric' => 'rbac.override.expired_tidied',
        ]);
        $this->info(sprintf('rbac:expire-overrides: %d override(s) tidied.', $tidied));

        return self::SUCCESS;
    }

    private function tidyBoundTenant(
        EventPublisher $events,
        AuditLogger $audit,
        CapabilityCacheInvalidator $invalidator,
    ): int {
        $count = 0;

        UserPermissionOverride::query()
            ->live()
            ->whereRaw('expires_at <= now()')
            ->orderBy('id')
            ->each(function (UserPermissionOverride $override) use ($events, $audit, $invalidator, &$count): void {
                DB::transaction(function () use ($override, $events, $audit): void {
                    // Guarded re-check under the row: only the first tidy wins
                    // (idempotency under concurrent scheduler runs).
                    $claimed = UserPermissionOverride::query()
                        ->whereKey($override->getKey())
                        ->whereNull('revoked_at')
                        ->update([
                            'revoked_at' => DB::raw('now()'),
                            'revoke_cause' => 'expired',
                        ]);
                    if ($claimed !== 1) {
                        return;
                    }

                    $events->publish('rbac.override.expired', [
                        'tenant_id' => $override->tenantId(),
                        'user_id' => $override->user_id,
                        'permission' => $override->permission,
                    ]);
                    $audit->record(
                        actionKey: 'rbac.override.expired',
                        entity: 'user',
                        entityId: $override->user_id,
                        actorType: AuditActor::System,
                        payload: ['permission' => $override->permission],
                    );
                });

                $invalidator->forgetUserId($override->user_id);
                $count++;
            });

        return $count;
    }
}
