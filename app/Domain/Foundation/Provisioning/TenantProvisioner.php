<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Provisioning;

use App\Domain\Foundation\Tenancy\TenantLifecycle;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Models\Tenant;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Idempotent tenant provisioning (Doc 01 FR-06, EC-04, EC-05, AC-05, FS-04, SS-01).
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * IT RUNS AS THE ORDINARY `app` ROLE, WITH NO RLS BYPASS. This is the part worth
 * understanding, because it looks impossible at first.
 *
 * `tenants` has an RLS policy keyed on `id`, so an INSERT is only permitted when
 * `app.tenant_id` already equals the id being inserted. So provisioning GENERATES the id
 * first, binds the context to it, and then writes — and the write satisfies the policy it
 * appears to precede. The circularity is the feature: the single most privileged operation in
 * the platform needs no privileged role, and `platform_admin` stays reserved for genuine
 * cross-tenant reads (SEC-02).
 * ────────────────────────────────────────────────────────────────────────────────
 *
 * EC-05 / AC-05: "idempotency key = tenant id + step". Not a flag this class checks and then
 * acts on — check-then-act is a race, and AC-05 runs two provisionings CONCURRENTLY. The key
 * is `unique (tenant_id, step)` in the database: the second worker's INSERT fails, and that
 * failure is how it learns another worker owns the step. No advisory locks, no coordination.
 *
 * FS-04: "tenant never partially active". `active` is set exactly once, after the last step,
 * by TenantLifecycle. A step that fails leaves the tenant `provisioning` with the failure
 * recorded, so Retry is safe and §9 can show which step failed.
 *
 * EC-04: state is re-checked between every step. A tenant suspended mid-flight halts at a step
 * boundary and stays resumable.
 */
final class TenantProvisioner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RlsSession $rls,
        private readonly TenantLifecycle $lifecycle,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
    ) {}

    /**
     * Create a tenant and run every provisioning step.
     *
     * @param  array<string, mixed>  $attributes  name, slug, region, language, timezone, currency
     * @return string the tenant id
     */
    public function provision(array $attributes, ?string $tenantId = null): string
    {
        // Generate first, bind second, write third — see the class docblock.
        $tenantId ??= (string) Str::uuid7();

        return $this->withTenantBound($tenantId, function () use ($tenantId, $attributes): string {
            // THE TENANT ROW COMES BEFORE ITS OWN STEP ROW, because
            // tenant_provisioning_steps.tenant_id references tenants(id). Recording the step
            // first violates that foreign key — and a violation aborts the whole transaction,
            // so the failure does not present as "step insert failed" but as every subsequent
            // statement failing with "current transaction is aborted". Found exactly that way.
            //
            // Its idempotency therefore comes from the tenants PRIMARY KEY rather than from the
            // step table: two workers racing to create the same tenant, and the loser gets a
            // duplicate-key violation. Same mechanism, different index.
            $this->createTenantRowIdempotently($tenantId, $attributes);

            $this->runStep(ProvisioningStep::TenantRecord, static fn () => null);

            foreach (ProvisioningStep::ordered() as $step) {
                if ($step === ProvisioningStep::TenantRecord) {
                    continue;
                }

                // EC-04: re-read state between steps. A tenant suspended mid-flight halts
                // here, cleanly, at a boundary — not part-way through a step.
                $tenant = Tenant::query()->find($tenantId);

                if ($tenant === null) {
                    // Another worker is mid-INSERT of the tenant row inside its own
                    // transaction, so this one cannot see it yet. Nothing to do: that worker
                    // owns the sequence.
                    return $tenantId;
                }

                if ($tenant->state() !== TenantState::Provisioning) {
                    Log::info('provisioning halted between steps', [
                        'tenant_id' => $tenantId,
                        'state' => $tenant->state()->value,
                        'next_step' => $step->value,
                    ]);

                    return $tenantId;
                }

                $this->runStep($step, fn () => $this->execute($step));
            }

            $this->finish($tenantId);

            return $tenantId;
        });
    }

    /**
     * Create the tenant row, tolerating a concurrent worker having created it first.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createTenantRowIdempotently(string $tenantId, array $attributes): void
    {
        try {
            // IN A NESTED TRANSACTION, i.e. behind a SAVEPOINT. In PostgreSQL a failed statement
            // aborts the entire transaction, so catching the duplicate-key violation is not
            // enough — without a savepoint to roll back to, every later statement fails with
            // "current transaction is aborted" and the real error is three screens away from
            // its cause.
            DB::transaction(function () use ($tenantId, $attributes): void {
                $this->createTenantRow($tenantId, $attributes);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return; // another worker created it; that worker owns nothing else by implication
            }

            throw $e;
        }
    }

    /**
     * Run one step, exactly once, ever.
     *
     * The INSERT is the lock. A second worker reaching the same step gets a unique-constraint
     * violation and returns — it does not wait, retry, or decide anything, because there is
     * nothing to decide: the step is owned.
     */
    private function runStep(ProvisioningStep $step, callable $work): void
    {
        $tenantId = $this->context->tenantId();

        try {
            // Savepoint, for the same reason as createTenantRowIdempotently().
            DB::transaction(function () use ($tenantId, $step): void {
                DB::table('tenant_provisioning_steps')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'step' => $step->value,
                    'status' => 'running',
                    'attempts' => 1,
                    'started_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            // 23505 = unique violation. Either this step already completed (a retry) or
            // another worker is running it right now (AC-05). Both mean: not ours.
            if ($this->isUniqueViolation($e)) {
                return;
            }

            throw $e;
        }

        try {
            $work();

            DB::table('tenant_provisioning_steps')
                ->where('tenant_id', $tenantId)
                ->where('step', $step->value)
                ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        } catch (Throwable $e) {
            // FS-04: the tenant stays `provisioning`, the step is marked failed with a
            // quotable reference, and the detail goes to logs where it can carry specifics
            // without surfacing them to an operator (C-13).
            $errorRef = (string) Str::uuid7();

            DB::table('tenant_provisioning_steps')
                ->where('tenant_id', $tenantId)
                ->where('step', $step->value)
                ->update(['status' => 'failed', 'error_ref' => $errorRef, 'updated_at' => now()]);

            Log::error('provisioning step failed', [
                'tenant_id' => $tenantId,
                'step' => $step->value,
                'error_ref' => $errorRef,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            DB::transaction(function () use ($step, $errorRef): void {
                $this->audit->record(
                    actionKey: 'tenant.provisioning_step_failed',
                    entity: 'tenant',
                    entityId: $this->context->tenantId(),
                    actorType: AuditActor::System,
                    payload: ['step' => $step->value, 'error_ref' => $errorRef],
                );
            });

            throw $e;
        }
    }

    /**
     * Mark the tenant active — once, after every step completed.
     *
     * Re-reads the step table rather than trusting the loop above: with two workers racing,
     * this one may have run only some steps, and only the worker that sees ALL of them
     * complete may finish. Otherwise both would attempt the transition and one would find the
     * tenant already active.
     */
    private function finish(string $tenantId): void
    {
        $completed = DB::table('tenant_provisioning_steps')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->pluck('step')
            ->map(static fn (mixed $step): string => is_string($step) ? $step : '')
            ->all();

        $required = array_map(
            static fn (ProvisioningStep $step): string => $step->value,
            ProvisioningStep::ordered(),
        );

        if (array_diff($required, $completed) !== []) {
            // FS-04: not every step is done, so the tenant stays `provisioning`. Either
            // another worker is still going or a step failed; both are resumable.
            return;
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null || $tenant->state() !== TenantState::Provisioning) {
            return; // already finished, by us or by the other worker
        }

        DB::transaction(function () use ($tenant, $required): void {
            $this->audit->record(
                actionKey: 'tenant.provisioned',
                entity: 'tenant',
                entityId: $tenant->tenantId(),
                actorType: AuditActor::System,
                payload: ['steps_completed' => $required],
            );

            $this->events->publish(
                name: 'tenant.provisioned',
                payload: ['tenant_id' => $tenant->tenantId()],
            );
        });

        // The one and only transition to active (FR-05/FS-04).
        $this->lifecycle->transition(
            tenant: $tenant,
            target: TenantState::Active,
            actor: AuditActor::System,
            reason: 'provisioning completed',
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTenantRow(string $tenantId, array $attributes): void
    {
        $tenant = new Tenant;
        $tenant->forceFill([
            'id' => $tenantId,
            'name' => $attributes['name'] ?? 'Unnamed operator',
            'slug' => $attributes['slug'] ?? $this->slugFor($attributes),
            // The database's initial-state trigger refuses any value but this (FS-04).
            'state' => TenantState::Provisioning->value,
            'region' => $attributes['region'] ?? 'et-central',
            'default_language_code' => $attributes['default_language_code'] ?? 'en',
            'timezone_name' => $attributes['timezone_name'] ?? 'Africa/Addis_Ababa',
            'currency_code' => $attributes['currency_code'] ?? 'ETB',
            'plan_flags' => $attributes['plan_flags'] ?? [],
            'quota_overrides' => $attributes['quota_overrides'] ?? [],
        ]);
        $tenant->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function slugFor(array $attributes): string
    {
        $name = $attributes['name'] ?? 'tenant';

        return Str::slug(is_string($name) ? $name : 'tenant').'-'.Str::lower(Str::random(6));
    }

    private function execute(ProvisioningStep $step): void
    {
        match ($step) {
            ProvisioningStep::DefaultSettings => $this->applyDefaultSettings(),

            // Doc 03 (S19) filled its declared seam: the nine Appendix B
            // templates, idempotent under this class's retry/concurrency
            // semantics (the seeder's own contract — see RoleSeeder).
            ProvisioningStep::DefaultRoles => app(\App\Domain\Rbac\RoleSeeder::class)->seed(),

            // The remaining seams. Recorded as completed with nothing created, because Doc 01
            // owns the sequencing and the idempotency while Docs 10/12 own the contents.
            // Logged at debug so a developer wondering why a step produced nothing finds the
            // answer rather than a mystery.
            ProvisioningStep::SlaPolicy,
            ProvisioningStep::BusinessHours,
            ProvisioningStep::KnowledgeBase => Log::debug('provisioning seam has no owner yet', [
                'step' => $step->value,
                'awaiting' => $step->owningDoc(),
            ]),

            ProvisioningStep::TenantRecord => null,
        };
    }

    /**
     * Materialise the settings a tenant chose at creation as explicit overrides.
     *
     * Only the ones that came from the create-tenant wizard (§8: "name, default language,
     * timezone, region"). Everything else stays on the registered default, because writing all
     * eleven defaults as overrides would make "reset to default" meaningless and would freeze
     * each tenant against a future default change.
     */
    private function applyDefaultSettings(): void
    {
        $tenant = Tenant::query()->findOrFail($this->context->tenantId());

        /** @var array<string, mixed> $chosen */
        $chosen = [
            'tenant.default_language' => $tenant->getAttribute('default_language_code'),
            'tenant.display_timezone' => $tenant->getAttribute('timezone_name'),
            'tenant.currency' => $tenant->getAttribute('currency_code'),
        ];

        foreach ($chosen as $key => $value) {
            // System write: no human is holding a stale form, so no optimistic check, and
            // tenantSurface: false because provisioning is the platform acting.
            $this->settings->set(
                key: $key,
                value: $value,
                actor: AuditActor::System,
                tenantSurface: false,
            );
        }
    }

    /**
     * Bind the tenant for the duration, restoring whatever was bound before.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTenantBound(string $tenantId, callable $callback): mixed
    {
        $previous = $this->context->tenantIdOrNull();

        $this->context->clear();
        $this->context->bind($tenantId, ResolutionSource::QueuedJob);
        $this->rls->set($tenantId);

        try {
            return $callback();
        } finally {
            $this->context->clear();

            if ($previous !== null) {
                $this->context->bind($previous, ResolutionSource::QueuedJob);
                $this->rls->set($previous);
            } else {
                $this->rls->clear();
            }
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->getCode() === '23505')
            || str_contains($e->getMessage(), 'duplicate key value violates unique constraint');
    }
}
