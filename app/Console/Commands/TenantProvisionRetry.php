<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantOption;
use App\Domain\Foundation\Tenancy\TenantState;
use App\Jobs\Foundation\ProvisionTenant;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * §9's Retry, as the minimal surface that exists today (Doc 01 FR-06, EC-05, §9).
 *
 * §9: a provisioning step failure "shows the failed step, error reference id, and Retry
 * (idempotent)". The platform console UI is deferred (BUILD-STATUS records it), so until it
 * ships, this command IS the retry path — same job, same idempotency: every completed step
 * is skipped via the (tenant_id, step) unique index, the failed one runs again (EC-05).
 *
 * Tenant-scoped by design, so it carries the T-05 console binding: --tenant is mandatory,
 * and there is no retry-everything mode. A stuck tenant is investigated one at a time by a
 * platform engineer reading its failed step row; a loop over all stuck tenants belongs to
 * the platform console when §8/§9 are built.
 */
final class TenantProvisionRetry extends Command
{
    use ResolvesTenantOption;

    protected $signature = 'tenant:provision-retry {--tenant= : The tenant to resume provisioning for}';

    protected $description = 'Re-dispatch the idempotent provisioning job for a tenant stuck in provisioning (Doc 01 §9 Retry)';

    public function handle(): int
    {
        $this->bindTenantFromOption();

        $tenant = Tenant::query()->firstOrFail();

        if ($tenant->state() !== TenantState::Provisioning) {
            $this->error(sprintf(
                'Tenant is %s, not provisioning — nothing to retry. A suspended tenant stays '
                .'suspended (EC-04); an active one is already done.',
                $tenant->state()->value,
            ));

            return self::FAILURE;
        }

        ProvisionTenant::dispatch($tenant->tenantId(), []);

        $this->info('Provisioning re-dispatched. Completed steps will be skipped (EC-05); the failed step runs again.');

        return self::SUCCESS;
    }
}
