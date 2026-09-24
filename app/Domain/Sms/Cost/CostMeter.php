<?php

declare(strict_types=1);

namespace App\Domain\Sms\Cost;

use App\Support\Events\EventPublisher;
use App\Support\Quota\Quota;
use App\Support\Quota\QuotaMeter;
use App\Support\Quota\QuotaSurface;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FR-19/FR-20 — the cost + quota meter, run at `sent`: the accrual (already
 * on the row from SendPipeline: segments × the routed gateway's
 * longest-prefix rate card) is COUNTED into the monthly-segment quota
 * (P-QUOTA-SMS's `Quota::SmsSegmentsPerMonth`, tenant-local calendar month),
 * and the monthly SPEND is read for the threshold (FR-19: `sms.cost.threshold`
 * → the `notify.sms.cost_threshold` category, once per tenant-month on the
 * first crossing — `sms.cost.threshold` event). Corrections are FORWARD ONLY
 * (FS-04): a DLR that re-rates writes `cost_corrected_amount` and annotates,
 * never rewriting the original accrual (the DlrApplier owns that write; this
 * meter reads both). Fixture/test rows are excluded at the source.
 */
final class CostMeter
{
    public function __construct(
        private readonly QuotaMeter $quota,
        private readonly SettingsRepository $settings,
        private readonly EventPublisher $events,
        private readonly TenantContext $context,
    ) {}

    /** At `sent`: count the segments into the monthly quota and check the spend threshold. */
    public function recordSent(int $segments, string $currency): void
    {
        if ($segments > 0) {
            try {
                $this->quota->allow(Quota::SmsSegmentsPerMonth, $segments, QuotaSurface::Staff);
            } catch (\Throwable $e) {
                // the quota is a soft cap for SMS accrual accounting, not a send gate here
                // (the send already happened); a full month LOGS, never crashes the pipeline
                Log::warning('sms cost: monthly segment quota crossed at accrual', ['metric' => 'sms.quota.segments_exceeded', 'error' => $e->getMessage()]);
            }
        }

        $this->checkThreshold($currency);
    }

    /** @return array{month: string, spend: string, currency: ?string, segments_used: int, segments_limit: int} the reports.view figure */
    public function monthlySpend(): array
    {
        /** @var object{month: string, spend: string, currency: ?string}|null $row */
        $row = DB::selectOne(
            "SELECT to_char(clock_timestamp() AT TIME ZONE COALESCE(t.timezone_name, 'UTC'), 'YYYY-MM') AS month,
                    COALESCE(SUM(COALESCE(m.cost_corrected_amount, m.cost_amount)), 0)::text AS spend,
                    MAX(m.cost_currency) AS currency
               FROM tenants t
               LEFT JOIN sms_messages m
                 ON m.tenant_id = t.id AND m.is_test = false
                AND m.sent_at IS NOT NULL
                AND to_char(m.sent_at AT TIME ZONE COALESCE(t.timezone_name, 'UTC'), 'YYYY-MM')
                  = to_char(clock_timestamp() AT TIME ZONE COALESCE(t.timezone_name, 'UTC'), 'YYYY-MM')
              WHERE t.id = ?
              GROUP BY t.timezone_name",
            [$this->context->tenantId()],
        );

        $headroom = $this->quota->headroom(Quota::SmsSegmentsPerMonth);
        if ($row === null) {
            return ['month' => '', 'spend' => '0', 'currency' => null, 'segments_used' => $headroom['used'], 'segments_limit' => $headroom['limit']];
        }

        return [
            'month' => $row->month,
            'spend' => $row->spend,
            'currency' => $row->currency,
            'segments_used' => $headroom['used'],
            'segments_limit' => $headroom['limit'],
        ];
    }

    /**
     * §14's informational conversion: the spend in the tenant's display
     * currency when `sms.cost.display_currency` + `sms.cost.display_rate` are
     * set — never a second source of truth, a display line (bcmul).
     *
     * @return array{currency: string, amount: string}|null
     */
    public function displaySpend(string $spend): ?array
    {
        $currency = $this->settings->get('sms.cost.display_currency');
        $rate = $this->settings->get('sms.cost.display_rate');
        if (! is_string($currency) || $currency === '' || ! is_string($rate) || ! is_numeric($rate)) {
            return null;
        }

        return ['currency' => $currency, 'amount' => bcmul(is_numeric($spend) ? $spend : '0', $rate, 4)];
    }

    private function checkThreshold(string $currency): void
    {
        $threshold = $this->settings->get('sms.cost.threshold');
        if (! is_array($threshold) || ! isset($threshold['amount']) || ! is_numeric($threshold['amount'])) {
            return;
        }
        $spend = $this->monthlySpend();
        if (bccomp(is_numeric($spend['spend']) ? $spend['spend'] : '0', (string) $threshold['amount'], 4) < 0) {
            return;
        }

        // once per tenant-month on the FIRST crossing: idempotent on the OUTBOX (the
        // publish lands there in this transaction; the events table fills only after
        // the relay runs) AND the events table (a prior month's relayed crossing)
        $alreadyThisMonth = DB::table('outbox')
            ->where('name', 'sms.cost.threshold')
            ->whereRaw("payload->>'month' = ?", [$spend['month']])
            ->exists()
            || DB::table('events')
                ->where('name', 'sms.cost.threshold')
                ->whereRaw("payload->>'month' = ?", [$spend['month']])
                ->exists();
        if ($alreadyThisMonth) {
            return;
        }

        DB::transaction(fn () => $this->events->publish('sms.cost.threshold', [
            'tenant_id' => $this->context->tenantId(),
            'month' => $spend['month'],
            'spend' => $spend['spend'],
            'threshold' => (string) $threshold['amount'],
            'currency' => is_string($threshold['currency'] ?? null) ? $threshold['currency'] : $currency,
        ]));
    }
}
