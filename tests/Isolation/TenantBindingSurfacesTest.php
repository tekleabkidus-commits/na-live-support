<?php

declare(strict_types=1);

use App\Console\Concerns\ResolvesTenantOption;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\ChannelTenantBinding;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantNotResolved;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * T-05, bindings 3 and 4 — sockets and the console. (Binding 1, HTTP, is proven by the
 * fuzz and matrix suites; binding 2, jobs, by ConcurrentProvisioningTest.)
 *
 * The socket contract: tenant from the AUTHENTICATED USER, never from the channel name.
 * The console contract: --tenant is an explicit opt-in to ONE tenant; skipping it does not
 * mean seeing everything, it means MissingTenantContext.
 */

/** A minimal command carrying the T-05 console binding, registered only for these tests. */
final class FixtureTenantBoundCommand extends Command
{
    use ResolvesTenantOption;

    protected $signature = 'fixture:tenant-bound {--tenant=}';

    public function handle(): int
    {
        $this->bindTenantFromOption();

        // Reads THROUGH the scoped model layer, so the output proves what the binding
        // let the command see: exactly its own tenant's row.
        $this->line(Tenant::query()->pluck('id')->implode(','));

        return self::SUCCESS;
    }
}

beforeEach(function (): void {
    app(Kernel::class)->registerCommand(new FixtureTenantBoundCommand);
});

it('binds the console to exactly the tenant named by --tenant (T-05)', function () {
    $a = activateTenant(createTenant());
    $b = activateTenant(createTenant());

    app(TenantContext::class)->clear();
    Artisan::call('fixture:tenant-bound', ['--tenant' => $a->tenantId()]);

    // The command saw its own tenant's row and nothing of tenant B's.
    expect(trim(Artisan::output()))->toBe($a->tenantId())
        ->and($a->tenantId())->not->toBe($b->tenantId());
});

it('refuses a console run with no --tenant BEFORE any model is touched', function () {
    createTenant();
    app(TenantContext::class)->clear();

    expect(fn () => Artisan::call('fixture:tenant-bound'))
        ->toThrow(RuntimeException::class, '--tenant=<uuid>');
});

it('gives an unknown --tenant the same TenantNotResolved as every other surface', function () {
    app(TenantContext::class)->clear();

    expect(fn () => Artisan::call('fixture:tenant-bound', ['--tenant' => (string) Str::uuid7()]))
        ->toThrow(TenantNotResolved::class);
});

it('resumes a stuck provisioning via tenant:provision-retry, idempotently (§9 Retry, EC-05)', function () {
    // createTenant() writes the row directly and leaves state=provisioning with no step
    // rows — exactly the stuck shape §9's Retry exists for. The sync queue runs the job
    // inline, so the retry completes provisioning here and now.
    $a = createTenant();

    app(TenantContext::class)->clear();
    $exit = Artisan::call('tenant:provision-retry', ['--tenant' => $a->tenantId()]);

    expect($exit)->toBe(0)
        ->and(asTenant($a, fn () => Tenant::query()->findOrFail($a->tenantId())->state())->value)->toBe('active');

    // Retrying a tenant that is NOT provisioning is refused, not re-run.
    app(TenantContext::class)->clear();
    expect(Artisan::call('tenant:provision-retry', ['--tenant' => $a->tenantId()]))->toBe(1);
});

it('authorizes a socket channel from the USER record and binds both layers (T-05)', function () {
    $a = activateTenant(createTenant());
    $user = asTenant($a, fn (): User => User::factory()->create());

    app(TenantContext::class)->clear();

    expect(app(ChannelTenantBinding::class)->authorize($user, $a->tenantId()))->toBeTrue()
        ->and(app(TenantContext::class)->tenantId())->toBe($a->tenantId());
});

it('refuses a channel addressed to another tenant, identically to one that does not exist (C-10)', function () {
    $a = activateTenant(createTenant());
    $b = activateTenant(createTenant());
    $user = asTenant($a, fn (): User => User::factory()->create());

    $binding = app(ChannelTenantBinding::class);

    app(TenantContext::class)->clear();
    $existing = $binding->authorize($user, $b->tenantId());
    $boundAfterExisting = app(TenantContext::class)->isBound();

    app(TenantContext::class)->clear();
    $phantom = $binding->authorize($user, (string) Str::uuid7());
    $boundAfterPhantom = app(TenantContext::class)->isBound();

    // Both refusals, both leaving nothing bound — and INDISTINGUISHABLE from each
    // other, so channel auth cannot be used to probe which tenants exist.
    expect($existing)->toBeFalse()
        ->and($phantom)->toBeFalse()
        ->and($boundAfterExisting)->toBeFalse()
        ->and($boundAfterPhantom)->toBeFalse();
});

it('refuses sockets for a suspended tenant — realtime is a staff surface (FR-05)', function () {
    $a = activateTenant(createTenant());
    $user = asTenant($a, fn (): User => User::factory()->create());
    suspendTenant($a);

    app(TenantContext::class)->clear();

    expect(app(ChannelTenantBinding::class)->authorize($user, $a->tenantId()))->toBeFalse()
        ->and(app(TenantContext::class)->isBound())->toBeFalse();
});
