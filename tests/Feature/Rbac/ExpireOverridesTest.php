<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * AC-07's trailing clauses + FR-09's division of labour: the RESOLVER is
 * enforcement truth (allowed-before/denied-after was proven cron-free in the
 * AC-02 matrix's EXPIRED cells); this suite proves the tidy pass — revoke
 * with cause, rbac.override.expired through the outbox, audit entry,
 * idempotent under re-runs, and blind to unexpired rows.
 */
it('tidies expired overrides: revoke(cause=expired) + outbox event + audit entry; unexpired rows untouched; idempotent (FR-09, AC-07)', function () {
    $tenant = createTenant();

    [$user, $expired, $live] = asTenant($tenant, function () {
        $admin = User::factory()->create();
        $role = Role::create([
            'name' => 'Expiry fixture role',
            'color' => 'accent-1',
            'permissions' => ['tickets.view'],
            'scopes' => ['tickets' => 'All'],
        ]);
        $user = User::factory()->create();
        $user->forceFill(['role_id' => $role->getKey()])->save();

        $mk = fn (string $permission, string $interval) => UserPermissionOverride::create([
            'user_id' => $user->identifier(),
            'permission' => $permission,
            'effect' => 'allow',
            'expires_at' => DB::selectOne("SELECT now() + interval '{$interval}' AS t")->t,
            'granted_by' => $admin->identifier(),
            'reason' => 'Expiry tidy fixture override.',
        ]);

        return [$user, $mk('customers.merge', '-1 hour'), $mk('customers.block', '+1 hour')];
    });
    commitFixtures();

    try {
        // Resolver truth, before any tidy: the expired grant already denies.
        asTenant($tenant, function () use ($user): void {
            expect(app(CapabilityMapProvider::class)->verdictFor($user, 'customers.merge')->allowed)->toBeFalse()
                ->and(app(CapabilityMapProvider::class)->verdictFor($user, 'customers.block')->allowed)->toBeTrue();
        });

        // The command runs UNBOUND (platform discovery + per-tenant
        // contexts) — PlatformConnection refuses a bound tenant, correctly.
        app(App\Support\Tenancy\TenantContext::class)->clear();
        app(App\Support\Tenancy\RlsSession::class)->clear();
        Artisan::call('rbac:expire-overrides');
        $firstRun = Artisan::output();

        asTenant($tenant, function () use ($expired, $live, $user): void {
            $tidied = $expired->fresh();
            expect($tidied->revoked_at)->not->toBeNull()
                ->and($tidied->revoke_cause)->toBe('expired')
                ->and($live->fresh()->revoked_at)->toBeNull();

            $event = DB::table('outbox')->where('name', 'rbac.override.expired')->first();
            expect($event)->not->toBeNull();
            $payload = json_decode((string) $event->payload, true);
            expect($payload)->toEqual([
                'tenant_id' => $tidied->tenantId(),
                'user_id' => $user->identifier(),
                'permission' => 'customers.merge',
            ]);

            $audit = DB::table('audit_log')->where('action_key', 'rbac.override.expired')->first();
            expect($audit)->not->toBeNull()
                ->and($audit->actor_type)->toBe('system')
                ->and(json_decode((string) $audit->payload, true))->toBe(['permission' => 'customers.merge']);
        });

        expect($firstRun)->toContain('1 override(s) tidied');

        // Idempotent: a second pass finds nothing.
        Artisan::call('rbac:expire-overrides');
        expect(Artisan::output())->toContain('0 override(s) tidied');
    } finally {
        cleanupTenants([$tenant->identifier()]);
    }
});
