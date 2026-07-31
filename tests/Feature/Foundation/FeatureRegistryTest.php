<?php

declare(strict_types=1);

use App\Support\Flags\FeatureRegistry;
use App\Support\Flags\UnregisteredFeature;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use Laravel\Pennant\Feature;

/**
 * FR-11 / ADR 0005 — registry #7: code-registered flags, tenant-scoped, no shadow state.
 * N-19 — flags hide, they never destroy.
 */
it('enumerates every registered flag with the full schema Doc 16 renders', function () {
    $all = app(FeatureRegistry::class)->all();

    expect($all)->not->toBeEmpty();

    foreach ($all as $entry) {
        expect($entry)->toHaveKeys(['key', 'label_i18n_key', 'default', 'tenant_toggleable', 'owning_doc'])
            ->and($entry['default'])->toBeBool()
            ->and($entry['tenant_toggleable'])->toBeBool()
            // N-03: the label is an i18n key with entries in BOTH launch languages,
            // not copy — Doc 16 will render it to operators.
            ->and(__($entry['label_i18n_key'], [], 'en'))->not->toBe($entry['label_i18n_key'])
            ->and(__($entry['label_i18n_key'], [], 'am'))->not->toBe($entry['label_i18n_key']);
    }
});

it('resolves a registered flag to its default for the bound tenant', function () {
    createTenant();

    expect(app(FeatureRegistry::class)->active('platform.emergency_read_only'))->toBeFalse();
});

it('throws on an unregistered flag — shadow flags cannot ship', function () {
    createTenant();

    expect(fn () => app(FeatureRegistry::class)->active('doc99.invented_flag'))
        ->toThrow(UnregisteredFeature::class, 'registries/features.php');
});

it('throws MissingTenantContext when no tenant is bound — a flag decision belongs to a tenant', function () {
    app(TenantContext::class)->clear();

    expect(fn () => app(FeatureRegistry::class)->active('platform.emergency_read_only'))
        ->toThrow(MissingTenantContext::class);
});

it('scopes flag decisions per tenant: activating for tenant A changes nothing for tenant B', function () {
    $a = createTenant();
    $b = createTenant();
    $registry = app(FeatureRegistry::class);

    // Toggle through Pennant directly — the toggle SURFACE is Doc 16's, but the
    // per-tenant scoping is Doc 01's contract and must hold before Doc 16 builds on it.
    Feature::for($a->tenantId())->activate('platform.emergency_read_only');

    expect(asTenant($a, fn (): bool => $registry->active('platform.emergency_read_only')))->toBeTrue()
        ->and(asTenant($b, fn (): bool => $registry->active('platform.emergency_read_only')))->toBeFalse();
});

it('never lets a registered flag key appear in a migration (N-19: flags hide, they never destroy)', function () {
    $keys = array_column(app(FeatureRegistry::class)->all(), 'key');
    $migrations = glob(base_path('database/migrations/*.php')) ?: [];

    expect($migrations)->not->toBeEmpty();

    $violations = [];
    foreach ($migrations as $file) {
        $source = (string) file_get_contents($file);
        foreach ($keys as $key) {
            if (str_contains($source, $key)) {
                $violations[] = sprintf('%s references flag "%s"', basename($file), $key);
            }
        }
    }

    expect($violations)->toBeEmpty(
        'A feature flag reached a migration. Flags guard rendering and routing only — '
        .'never schema, backfills, or deletes (N-19): '.implode('; ', $violations),
    );
});
