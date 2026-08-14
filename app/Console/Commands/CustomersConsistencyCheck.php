<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Customers\Rollups\CustomerRollups;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NFR-05's weekly checker (the Doc 07 inbox:consistency-check pattern —
 * productized, scheduled, and the same recompute code path the consumer
 * uses, so "checker" and "writer" cannot hold two readings of a rollup).
 *
 * DRIFT SEMANTICS, stated because a windowed rollup decays by design:
 * `contacts_30d_count` goes stale as tickets age out of its trailing window
 * with NO event — that decay is an EXPECTED residual (FS-03's "weekly
 * checker heals residuals") and is healed SILENTLY. Drift on any OTHER
 * column means an event was missed or a handler mis-wrote — THAT alerts
 * (`customers.rollup.drift` metric, NFR-05's "any drift alerts") and heals.
 */
final class CustomersConsistencyCheck extends Command
{
    protected $signature = 'customers:consistency-check';

    protected $description = 'NFR-05: verify customer rollups against truth per tenant; heal residuals; alert on non-decay drift';

    public function handle(CustomerRollups $rollups, TenantContext $context, RlsSession $rls): int
    {
        /** @var \Illuminate\Support\Collection<int, string> $tenants */
        $tenants = DB::connection('pgsql_platform')->table('tenants')->pluck('id');
        $healed = 0;
        $alerted = 0;

        foreach ($tenants as $tenantId) {
            $context->clear();
            $context->bind($tenantId, ResolutionSource::Console);
            $rls->set($tenantId);

            /** @var \Illuminate\Support\Collection<int, string> $customerIds */
            $customerIds = DB::table('customers')->whereNull('merged_into_id')->pluck('id');

            foreach ($customerIds as $customerId) {
                // Ruling A: alertableDrift carries the DIRECTION discriminator —
                // window decay (stored > recomputed) heals quietly; an
                // under-count alerts like any other column.
                $alertable = $rollups->alertableDrift($customerId);
                $drifted = $rollups->drift($customerId);

                if ($drifted === []) {
                    continue;
                }

                $rollups->recompute($customerId);
                $healed++;

                if ($alertable !== []) {
                    $alerted++;
                    Log::warning('customer rollup drift healed', [
                        'metric' => 'customers.rollup.drift',
                        'customer_id' => $customerId,
                        'columns' => $alertable,
                    ]);
                }
            }
        }

        $context->clear();
        $rls->clear();

        $this->info("customers:consistency-check — {$healed} healed, {$alerted} alerted.");

        return self::SUCCESS;
    }
}
