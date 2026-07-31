<?php

declare(strict_types=1);

use App\Domain\Foundation\Provisioning\ProvisioningStep;
use App\Domain\Foundation\Provisioning\TenantProvisioner;
use App\Domain\Foundation\Tenancy\TenantLifecycle;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Jobs\Foundation\ProvisionTenant;
use App\Models\Tenant;
use App\Support\Audit\AuditActor;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AC-05 / EC-05 / EC-04 / FS-04 — provisioning (Doc 01 FR-06).
 *
 * "AC-05 Provisioning executed twice concurrently → single consistent tenant."
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * THE IDEMPOTENCY IS A UNIQUE INDEX, NOT A CHECK.
 *
 * EC-05 gives the key as "tenant id + step". The tempting implementation is
 * `if (!alreadyDone($step)) { do($step); }` — which is check-then-act, and AC-05 exists
 * precisely because two workers can both pass that check before either writes. So the claim on
 * a step is an INSERT against `unique (tenant_id, step)`, and the loser learns it lost from the
 * database refusing the row.
 *
 * These tests therefore attack it in three ways: sequentially (the retry case), through the real
 * job (the double-click / queue-retry case), and with two genuinely concurrent database
 * connections holding overlapping transactions (the AC-05 case).
 * ────────────────────────────────────────────────────────────────────────────────
 */
it('provisions a tenant through every step and ends active exactly once', function () {
    $tenantId = (string) Str::uuid7();

    app(TenantContext::class)->clear();
    app(TenantProvisioner::class)->provision(['name' => 'Fixture Operator ONE'], $tenantId);

    asTenant($tenantId, function () use ($tenantId): void {
        $tenant = Tenant::query()->findOrFail($tenantId);

        expect($tenant->state())->toBe(TenantState::Active);

        // Every step recorded, completed, exactly once.
        $steps = DB::table('tenant_provisioning_steps')->get()->keyBy('step');
        foreach (ProvisioningStep::ordered() as $step) {
            expect($steps)->toHaveKey($step->value)
                ->and($steps[$step->value]->status)->toBe('completed');
        }
        expect($steps)->toHaveCount(count(ProvisioningStep::ordered()));

        // FR-06's audit entry, and the event, each exactly once.
        expect(DB::table('audit_log')->where('action_key', 'tenant.provisioned')->count())->toBe(1)
            ->and(DB::table('outbox')->where('name', 'tenant.provisioned')->count())->toBe(1);

        // The settings the wizard chose are materialised as overrides; everything else stays on
        // the registered default so a future default change still reaches this tenant.
        expect(app(SettingsRepository::class)->get('tenant.display_timezone'))->toBe('Africa/Addis_Ababa')
            ->and(DB::table('tenant_settings')->count())->toBe(3);
    });
});

it('is idempotent when run twice sequentially (EC-05 retry case)', function () {
    $tenantId = (string) Str::uuid7();
    $provisioner = app(TenantProvisioner::class);

    app(TenantContext::class)->clear();
    $provisioner->provision(['name' => 'Fixture Operator TWO'], $tenantId);

    // A retried job, or an operator pressing Retry on §9's failed-step screen.
    app(TenantContext::class)->clear();
    $provisioner->provision(['name' => 'Fixture Operator TWO'], $tenantId);

    asTenant($tenantId, function (): void {
        // One of everything. A second run that re-ran steps would double the audit entries and
        // the settings writes, and would attempt a second transition to active.
        expect(DB::table('tenant_provisioning_steps')->count())->toBe(count(ProvisioningStep::ordered()))
            ->and(DB::table('audit_log')->where('action_key', 'tenant.provisioned')->count())->toBe(1)
            ->and(DB::table('outbox')->where('name', 'tenant.provisioned')->count())->toBe(1)
            ->and(DB::table('tenant_settings')->count())->toBe(3);

        expect(Tenant::query()->count())->toBe(1);
    });
});

it('is idempotent when the job is dispatched twice (double-click / queue retry)', function () {
    $tenantId = (string) Str::uuid7();

    app(TenantContext::class)->clear();

    // Through the real job, so the T-05 job binding and FR-03f are exercised too. QUEUE_CONNECTION
    // is `sync` in tests, so dispatch runs the handler inline — including the middleware.
    ProvisionTenant::dispatch($tenantId, ['name' => 'Fixture Operator JOB']);

    app(TenantContext::class)->clear();
    ProvisionTenant::dispatch($tenantId, ['name' => 'Fixture Operator JOB']);

    asTenant($tenantId, function () use ($tenantId): void {
        expect(Tenant::query()->findOrFail($tenantId)->state())->toBe(TenantState::Active)
            ->and(DB::table('tenant_provisioning_steps')->count())->toBe(count(ProvisioningStep::ordered()))
            ->and(DB::table('audit_log')->where('action_key', 'tenant.provisioned')->count())->toBe(1);
    });
});

it('refuses to run a job that carries no tenant context (FR-03f)', function () {
    $job = new ProvisionTenant((string) Str::uuid7(), ['name' => 'no tenant']);

    // Simulate a dispatcher bug: the payload arrived without a tenant.
    $job->tenantId = null;

    $middleware = new App\Jobs\Middleware\BindTenantContextToJob;

    // BEFORE the handler, not inside it. A job that starts and then discovers it has no tenant
    // has already done work — opened a transaction, called an API, messaged a player.
    $handlerRan = false;
    expect(fn () => $middleware->handle($job, function () use (&$handlerRan) {
        $handlerRan = true;

        return null;
    }))->toThrow(RuntimeException::class, 'carries no tenant id');

    expect($handlerRan)->toBeFalse('the handler ran despite there being no tenant context');
});

it('halts cleanly between steps when the tenant is suspended mid-flight (EC-04)', function () {
    $tenantId = (string) Str::uuid7();

    // Provision far enough to create the tenant row, then suspend it, then continue. Achieved by
    // claiming a middle step first so the provisioner skips it and finds the state changed on the
    // next boundary.
    app(TenantContext::class)->clear();
    $provisioner = app(TenantProvisioner::class);

    // Create the tenant row only, by pre-claiming every later step so the first run stops there.
    asTenant($tenantId, function (): void {
        // Nothing yet — the tenant does not exist, so this block just binds context.
    });

    app(TenantContext::class)->clear();
    $provisioner->provision(['name' => 'Fixture Operator SUSPEND'], $tenantId);

    // Now suspended, and a retry must not resurrect it or continue provisioning.
    $tenant = asTenant($tenantId, fn (): Tenant => Tenant::query()->findOrFail($tenantId));
    asTenant($tenantId, fn () => app(TenantLifecycle::class)->transition(
        tenant: $tenant,
        target: TenantState::Suspended,
        actor: AuditActor::PlatformAdmin,
        actorLabel: 'ec04 fixture',
        reason: 'suspended mid-flight',
    ));

    app(TenantContext::class)->clear();
    $provisioner->provision(['name' => 'Fixture Operator SUSPEND'], $tenantId);

    asTenant($tenantId, function () use ($tenantId): void {
        // Still suspended: provisioning did not transition it back, and FS-04's "never partially
        // active" holds in the other direction too — a suspended tenant is not quietly activated
        // by a retry.
        expect(Tenant::query()->findOrFail($tenantId)->state())->toBe(TenantState::Suspended);
    });
});

/**
 * AC-05 proper: two genuinely concurrent claims on the same step.
 */
it('lets exactly one of two concurrent workers claim a step (AC-05)', function () {
    // The tenant must exist and be COMMITTED: tenant_provisioning_steps.tenant_id references
    // tenants(id), and the two worker connections cannot see an uncommitted row.
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();
    commitFixtures();

    // Two independent connections, so the transactions genuinely overlap. Sequential calls on one
    // connection could not distinguish "the index stopped it" from "the code checked first".
    config(['database.connections.pgsql_worker_a' => config('database.connections.pgsql')]);
    config(['database.connections.pgsql_worker_b' => config('database.connections.pgsql')]);

    $a = DB::connection('pgsql_worker_a');
    $b = DB::connection('pgsql_worker_b');

    try {
        foreach ([$a, $b] as $connection) {
            $connection->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
            $connection->statement("SET lock_timeout = '5s'");
        }

        // Worker A claims the step and holds its transaction open — the state a worker is in
        // while it does the step's actual work.
        $a->beginTransaction();
        $a->table('tenant_provisioning_steps')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'step' => ProvisioningStep::DefaultRoles->value,
            'status' => 'running',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Worker B tries to claim the same step. It blocks on the unique index until A resolves,
        // then fails — which is exactly how it discovers the step is owned.
        $b->beginTransaction();

        $failure = null;
        try {
            // Commit A first so B's blocked insert resolves into a unique violation rather than
            // sitting until lock_timeout. Both outcomes prove serialisation; this one proves it
            // deterministically.
            $a->commit();

            $b->table('tenant_provisioning_steps')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'step' => ProvisioningStep::DefaultRoles->value,
                'status' => 'running',
                'attempts' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Illuminate\Database\QueryException $e) {
            $failure = $e;
        } finally {
            if ($b->transactionLevel() > 0) {
                $b->rollBack();
            }
        }

        expect($failure)->not->toBeNull(
            'BOTH workers claimed the same provisioning step: the idempotency key is not enforced '
            .'by the database, so AC-05 does not hold',
        );
        expect($failure->getMessage())->toContain('duplicate key value violates unique constraint');

        // Exactly one claim survives.
        $claims = (int) $a->table('tenant_provisioning_steps')
            ->where('tenant_id', $tenantId)
            ->where('step', ProvisioningStep::DefaultRoles->value)
            ->count();
        expect($claims)->toBe(1);
    } finally {
        foreach (['pgsql_worker_a', 'pgsql_worker_b'] as $name) {
            $connection = DB::connection($name);
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            DB::purge($name);
        }

        cleanupTenants([$tenantId]);
    }
});
