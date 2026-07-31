<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Retention;

use App\Domain\Foundation\Tenancy\TenantState;
use App\Models\Tenant;
use App\Support\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * BR-05's decision, as a pure function of facts (Doc 01 BR-05).
 *
 * The FROZEN CONTRACT for Doc 04's ingestion: on every inbound message for a suspended
 * tenant, ask this policy; Full → store the message as normal, ColdSummary → ACK the
 * channel and call ColdSummaryStore::record() instead. Doc 04 must prove
 * ACK-but-summarize against exactly this interface (inherited task in BUILD-STATUS).
 *
 * Inputs are facts, not queries-with-clocks-inside: the tenant row (whose suspended_at
 * has TenantLifecycle as its single writer — owner ruling, this session) and a caller-
 * supplied "now". US-02's promise ("reactivation loses nothing") is why every unclear
 * case resolves to Full: cold summary DISCARDS message bodies, so it is only ever
 * entered on positive evidence that the retention window has passed.
 */
final class InboundAccrualPolicy
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function modeFor(Tenant $tenant, CarbonImmutable $now): InboundAccrual
    {
        if ($tenant->state() !== TenantState::Suspended) {
            return InboundAccrual::Full;
        }

        $suspendedAt = $tenant->getAttribute('suspended_at');

        if (! $suspendedAt instanceof DateTimeInterface) {
            // A suspended tenant with no suspension timestamp should be impossible once
            // TenantLifecycle stamps it — but if it happens, the data-preserving answer
            // is Full, loudly: discarding messages on missing evidence inverts US-02.
            Log::warning('suspended tenant has no suspended_at; accruing in full', [
                'tenant_id' => $tenant->tenantId(),
                'metric' => 'retention.suspended_at_missing',
            ]);

            return InboundAccrual::Full;
        }

        $days = $this->settings->get('tenant.suspended_inbound_retention_days');

        if (! is_int($days)) {
            throw new RuntimeException(
                'tenant.suspended_inbound_retention_days did not decode to an int — check its registry entry.',
            );
        }

        $coldFrom = CarbonImmutable::instance($suspendedAt)->addDays($days);

        return $now->greaterThan($coldFrom) ? InboundAccrual::ColdSummary : InboundAccrual::Full;
    }
}
