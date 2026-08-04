<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\CapabilityMapBuilder;
use App\Support\Rbac\CapabilityMapProvider;

/**
 * Doc 03 NFR-01, PARTIAL BY DESIGN (the pre-agreed Doc 19 L1 bucket): every
 * number here is DEV-HARDWARE-INDICATIVE — zero contention, laptop container,
 * no L1 load profile. Necessary condition, never the sufficient one; the
 * traceability record carries the formal closure as Doc 19's.
 *
 * NFR-01 rows: ≤0.3 ms p99 per check from the capability cache; cold rebuild
 * ≤20 ms. §12's page shape (≤30 checks batched into one map read) is why the
 * per-check row measures a verdict read off an ALREADY-FETCHED map — that is
 * what a request does — while the cache-hit map fetch itself is measured
 * separately (it happens once per request, not thirty times).
 */
it('meets NFR-01 indicatively: cached check ≤0.3 ms p99, cold rebuild ≤20 ms (Doc 19 L1 owns the real proof)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $role = Role::create([
            'name' => 'Perf fixture role',
            'color' => 'accent-1',
            'permissions' => ['tickets.view', 'tickets.reply', 'customers.view', 'reports.view'],
            'scopes' => ['tickets' => 'Team', 'customers' => 'Own', 'reports' => 'Dept'],
        ]);
        $user = User::factory()->create();
        $user->forceFill(['role_id' => $role->getKey()])->save();

        $provider = app(CapabilityMapProvider::class);

        // One map read per page (T-02/§12), then ≤30 in-memory checks.
        $stats = measure(function () use ($provider, $user): void {
            $map = $provider->mapFor($user);
            foreach (['tickets.view', 'tickets.reply', 'customers.view', 'reports.view', 'audit.view'] as $key) {
                $map->allows($key);
            }
        }, 500);
        reportBudget('NFR-01 cached map + checks', 0.3, $stats);
        assertBudget('NFR-01 cached map + checks', $stats, 0.3);

        $builder = app(CapabilityMapBuilder::class);
        $cold = measure(function () use ($builder, $user): void {
            $builder->build($user);
        }, 200);
        reportBudget('NFR-01 cold map rebuild', 20.0, $cold);
        assertBudget('NFR-01 cold map rebuild', $cold, 20.0);
    });
});
