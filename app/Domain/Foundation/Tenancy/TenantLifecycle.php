<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Tenancy;

use App\Models\Tenant;
use App\Models\TenantKey;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * THE ONLY code path that may change a tenant's state (Doc 01 FR-05).
 *
 * "Only" is enforced twice, in two different places, because a service that is
 * merely the conventional way to do something is not a guarantee:
 *
 *   here      the transition is validated against TenantState's graph, audited,
 *             and published as a domain event through the outbox.
 *   database  a BEFORE UPDATE trigger rejects any state change that arrives
 *             without `app.lifecycle_transition` set — a flag this class sets in
 *             exactly one statement, transaction-locally, immediately before its
 *             UPDATE. So `$tenant->update(['state' => 'active'])` from anywhere
 *             else in 19 modules' worth of future code raises a database error,
 *             not a silent success.
 *
 * WHY `set_config(..., true)` — TRANSACTION-local — HERE, when RlsSession
 * deliberately uses session-level. Opposite requirements. The RLS variable must
 * outlive the transaction because most queries run outside one. This flag must NOT
 * outlive the transaction: if it leaked to the connection, every later UPDATE in
 * that request — and under Octane, in later requests on the same connection —
 * would carry permission to change tenant state. The narrow scope IS the control.
 */
final class TenantLifecycle
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
    ) {}

    /**
     * Move a tenant to a new state.
     *
     * @param  AuditActor  $actor  who is doing this; platform_admin for console
     *                             actions, system for provisioning completion
     *
     * @throws DomainException when the transition is not in the FR-05 graph
     */
    public function transition(
        Tenant $tenant,
        TenantState $target,
        AuditActor $actor,
        ?string $actorId = null,
        ?string $actorLabel = null,
        ?string $reason = null,
    ): Tenant {
        $current = $tenant->state();

        if ($current === $target) {
            // Idempotent by design: the console's suspend button, a retried job,
            // and a double-clicked form all converge on the same state. Treating
            // this as an error would make every caller write the same guard.
            return $tenant;
        }

        if (! $current->canTransitionTo($target)) {
            throw new DomainException(sprintf(
                'Illegal tenant lifecycle transition %s -> %s (Doc 01 FR-05). Legal from %s: %s.',
                $current->value,
                $target->value,
                $current->value,
                $current->allowedTransitions() === []
                    ? 'nothing — it is a terminal state'
                    : implode(', ', array_map(
                        static fn (TenantState $state): string => $state->value,
                        $current->allowedTransitions(),
                    )),
            ));
        }

        return DB::transaction(function () use ($tenant, $current, $target, $actor, $actorId, $actorLabel, $reason): Tenant {
            // The database's permission slip for this one UPDATE, in this one
            // transaction. See the class docblock on why it is not session-scoped.
            DB::statement("SELECT set_config('app.lifecycle_transition', 'on', true)");

            // Deliberately a query-builder UPDATE rather than $tenant->save():
            // `state` is not fillable on the model (see App\Models\Tenant) so that
            // no mass-assignment anywhere can reach it, which means the one
            // legitimate writer has to go around Eloquent's setters too.
            Tenant::query()
                ->whereKey($tenant->tenantId())
                ->update(['state' => $target->value]);

            $tenant->refresh();

            $this->audit->record(
                actionKey: 'tenant.state_changed',
                entity: 'tenant',
                entityId: $tenant->tenantId(),
                actorType: $actor,
                payload: [
                    'old_state' => $current->value,
                    'new_state' => $target->value,
                    'reason' => $reason,
                ],
                actorId: $actorId,
                actorLabel: $actorLabel,
            );

            if ($target === TenantState::Archived) {
                $this->revokeEveryKey($tenant);
            }

            // Through the outbox, never dispatched directly (C-06/T-03): a
            // subscriber must not learn a tenant was suspended by a transaction
            // that then rolled back.
            $this->events->publish(
                name: 'tenant.state_changed',
                payload: [
                    'tenant_id' => $tenant->tenantId(),
                    'old_state' => $current->value,
                    'new_state' => $target->value,
                ],
            );

            return $tenant;
        });
    }

    /**
     * FR-05's archived clause: "all keys revoked".
     *
     * Revocation happens IN THE SAME TRANSACTION as the state change, which is the
     * only ordering that cannot leave a gap. Archive-then-revoke would leave a window
     * where the tenant is archived and its Telegram bot key still resolves;
     * revoke-then-archive would leave one where the keys are dead but the tenant still
     * looks active. Both windows are short and neither is acceptable for the state
     * that means "this relationship has ended".
     *
     * The effect is that an archived tenant's channels stop resolving at all —
     * resolution returns 404 (SEC-06) rather than reaching the state check. That is
     * stronger than a 403 and it is what "keys revoked" should mean: the credential is
     * not refused, it ceases to exist.
     *
     * Each revocation is audited separately rather than as one bulk entry. An operator
     * asking "when did my widget stop working" needs an answer per key, and
     * `tenant.key_revoked` is registered with a `system` actor precisely for this path.
     */
    private function revokeEveryKey(Tenant $tenant): void
    {
        $keys = TenantKey::query()->whereNull('revoked_at')->get();

        foreach ($keys as $key) {
            $keyId = $key->getKey();

            if (! is_string($keyId)) {
                throw new \LogicException('TenantKey without a string id while archiving.');
            }

            $kind = $key->getAttribute('kind');
            $prefix = $key->getAttribute('prefix');

            if (! is_string($kind) || ! is_string($prefix)) {
                throw new \LogicException(sprintf(
                    'TenantKey %s is missing kind or prefix; both are NOT NULL columns, so this means the '
                    .'model was built from a partial select.',
                    $keyId,
                ));
            }

            $key->forceFill(['revoked_at' => now()])->save();

            $this->audit->record(
                actionKey: 'tenant.key_revoked',
                entity: 'tenant_key',
                entityId: $keyId,
                actorType: AuditActor::System,
                payload: [
                    'kind' => $kind,
                    'prefix' => $prefix,
                ],
            );
        }
    }
}
