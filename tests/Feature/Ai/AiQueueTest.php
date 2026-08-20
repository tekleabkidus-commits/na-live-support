<?php

declare(strict_types=1);

use App\Domain\Ai\Concurrency\TenantSemaphore;

/**
 * FR-03 (ADR 0017): the ai queue family's priority lanes and the per-tenant
 * concurrency semaphore. The queued task jobs themselves land with their
 * pipeline callers (steps 6+) — this suite pins the family's SHAPE.
 */
it('ADR 0017 (amended): ai and ai-background are SEPARATE supervisors, one family each (Doc 04 FS-03 physics)', function (): void {
    $families = config('horizon.defaults');

    expect($families)->toHaveKey('ai')
        ->and($families['ai']['queue'])->toBe(['ai'])
        ->and($families)->toHaveKey('ai-background')
        ->and($families['ai-background']['queue'])->toBe(['ai-background']);
});

it('FR-03: the per-tenant semaphore admits to the registered cap and refuses past it; release frees a slot', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);

    config(); // no-op; the cap comes from the registered setting default (20)
    $semaphore = app(TenantSemaphore::class);

    $granted = 0;
    for ($i = 0; $i < 21; $i++) {
        if ($semaphore->acquire()) {
            $granted++;
        }
    }
    expect($granted)->toBe(20, 'the semaphore did not stop at the registered cap');

    $semaphore->release();
    expect($semaphore->acquire())->toBeTrue('a released slot was not reusable');

    for ($i = 0; $i < 20; $i++) {
        $semaphore->release();
    }
});

it('FR-03: the semaphore is per-tenant — one tenant at cap starves nobody else', function (): void {
    $tenantA = createTenant();
    bindTenant($tenantA);
    $semaphore = app(TenantSemaphore::class);
    for ($i = 0; $i < 20; $i++) {
        expect($semaphore->acquire())->toBeTrue();
    }
    expect($semaphore->acquire())->toBeFalse();

    $tenantB = createTenant();
    bindTenant($tenantB);
    expect(app(TenantSemaphore::class)->acquire())->toBeTrue('tenant B starved by tenant A — the C-07 prefix is not isolating');
});

it('the ai.concurrency.per_tenant setting is registered with platform-maximum validation', function (): void {
    $settings = require base_path('registries/settings.php');
    $entry = collect($settings)->firstWhere('key', 'ai.concurrency.per_tenant');

    expect($entry)->not->toBeNull()
        ->and($entry['tenant_editable'])->toBeFalse()
        ->and($entry['validation'])->toContain('max:100')
        ->and($entry['owning_doc'])->toBe('Doc09');
});
