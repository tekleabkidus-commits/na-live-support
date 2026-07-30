<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Exceptions\CrossTenantWrite;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\TenantContext;

/**
 * Layer 1 — the application-level tenant scope (Doc 01 T-01.1, T-05).
 *
 * These tests assert the SCOPE's behaviour. They run with RLS also active, so a
 * pass here does not by itself prove the scope works — the isolation suite's
 * falsification protocol (tests/Isolation/TwoLayerIndependenceTest.php) is what
 * separates the two layers.
 */
it('filters reads to the bound tenant', function () {
    $a = createTenant();
    $userA = asTenant($a, fn () => User::factory()->create());

    $b = createTenant();
    $userB = asTenant($b, fn () => User::factory()->create());

    asTenant($a, function () use ($userA, $userB) {
        $visible = User::query()->pluck('id')->all();

        expect($visible)->toContain((string) $userA->getKey())
            ->and($visible)->not->toContain((string) $userB->getKey());
    });
});

it('auto-fills tenant_id on create from the context, never from the caller', function () {
    $tenant = createTenant();

    $user = asTenant($tenant, fn () => User::factory()->create());

    expect($user->getAttribute('tenant_id'))->toBe((string) $tenant->getKey());
});

it('throws when a tenant-scoped model is read with no bound context', function () {
    createTenant();
    app(TenantContext::class)->clear();

    expect(fn () => User::query()->count())->toThrow(MissingTenantContext::class);
});

it('throws when a tenant-scoped model is created with no bound context', function () {
    createTenant();
    app(TenantContext::class)->clear();

    expect(fn () => User::factory()->create())->toThrow(MissingTenantContext::class);
});

it('throws when a model loaded under one tenant is saved under another', function () {
    $a = createTenant();
    $user = asTenant($a, fn () => User::factory()->create());

    $b = createTenant();

    // The realistic version of this bug: an object that outlived its request.
    asTenant($b, function () use ($user) {
        $user->setAttribute('name', 'renamed under the wrong tenant');

        expect(fn () => $user->save())->toThrow(CrossTenantWrite::class);
    });
});

it('scopes the tenants table to the bound tenant so one tenant cannot observe another', function () {
    $a = createTenant();
    $b = createTenant();

    asTenant($a, function () use ($a, $b) {
        expect(Tenant::query()->count())->toBe(1)
            ->and(Tenant::query()->first()?->getKey())->toBe((string) $a->getKey())
            ->and(Tenant::query()->find((string) $b->getKey()))->toBeNull();
    });
});

it('refuses to rebind a context that is already bound', function () {
    $a = createTenant();
    $b = createTenant();

    bindTenant($a);

    expect(fn () => app(TenantContext::class)->bind((string) $b->getKey(), ResolutionSource::StaffSession))
        ->toThrow(LogicException::class);
});

it('refuses a platform operation while a tenant is bound (SEC-02)', function () {
    $tenant = createTenant();
    bindTenant($tenant);

    expect(fn () => PlatformConnection::run(
        PlatformActor::PlatformConsole,
        'listing tenants',
        fn () => Tenant::query()->count(),
    ))->toThrow(LogicException::class);
});
