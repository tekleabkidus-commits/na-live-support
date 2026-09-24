<?php

declare(strict_types=1);

namespace App\Domain\Sms\Reports;

use App\Domain\Sms\Cost\CostMeter;
use Illuminate\Support\Facades\DB;

/**
 * The Doc 15 report feed (B5 vocabulary) — ids, categories and figures only,
 * never a phone or a body: the sent/delivered/undelivered/opt-out counts, the
 * DLR latency, the SPEND (locked; §14), the per-gateway card. `is_fixture`
 * and `is_test` rows are excluded AT THE SOURCE (N-14's neighborhood — a
 * fixture result never colors a figure). SEC-06: this reader is
 * `reports.view`-gated at the controller.
 */
final class SmsFacts
{
    public function __construct(private readonly CostMeter $cost) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        /** @var object{sent: int, delivered: int, undelivered: int, failed: int, expired: int}|null $states */
        $states = DB::table('sms_messages')
            ->where('is_test', false)
            ->selectRaw("
                count(*) FILTER (WHERE state IN ('sent','delivered','undelivered','expired')) AS sent,
                count(*) FILTER (WHERE state = 'delivered') AS delivered,
                count(*) FILTER (WHERE state = 'undelivered') AS undelivered,
                count(*) FILTER (WHERE state = 'failed') AS failed,
                count(*) FILTER (WHERE state = 'expired') AS expired
            ")->first();

        $optOuts = (int) DB::table('sms_optouts')->whereNull('restored_at')->count();

        /** @var object{avg_latency_s: ?float}|null $latency */
        $latency = DB::table('sms_messages')
            ->where('is_test', false)->whereNotNull('delivered_at')->whereNotNull('sent_at')
            ->selectRaw('avg(extract(epoch from (delivered_at - sent_at))) AS avg_latency_s')->first();

        $spend = $this->cost->monthlySpend();

        return [
            'sent' => (int) ($states->sent ?? 0),
            'delivered' => (int) ($states->delivered ?? 0),
            'undelivered' => (int) ($states->undelivered ?? 0),
            'failed' => (int) ($states->failed ?? 0),
            'expired' => (int) ($states->expired ?? 0),
            'opt_out_count' => $optOuts,
            'dlr_latency_avg_seconds' => $latency?->avg_latency_s === null ? null : round((float) $latency->avg_latency_s, 2),
            'spend' => ['month' => $spend['month'], 'amount' => $spend['spend'], 'currency' => $spend['currency']],
            'spend_display' => $this->cost->displaySpend($spend['spend']),
            'segments_used' => $spend['segments_used'],
            'segments_limit' => $spend['segments_limit'],
            'per_gateway' => $this->perGateway(),
        ];
    }

    /** @return list<array<string, mixed>> per-gateway sent/spend, filter vocabulary Template · Gateway (B5) */
    private function perGateway(): array
    {
        $rows = DB::table('sms_messages')
            ->where('sms_messages.is_test', false)
            ->whereNotNull('sms_messages.gateway_id')
            ->join('sms_gateways', 'sms_gateways.id', '=', 'sms_messages.gateway_id')
            ->groupBy('sms_gateways.id', 'sms_gateways.name')
            ->selectRaw("sms_gateways.id AS gateway_id, sms_gateways.name AS gateway_name,
                count(*) FILTER (WHERE sms_messages.state IN ('sent','delivered','undelivered','expired')) AS sent,
                COALESCE(sum(COALESCE(sms_messages.cost_corrected_amount, sms_messages.cost_amount)), 0)::text AS spend")
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = ['gateway_id' => $row->gateway_id, 'gateway_name' => $row->gateway_name, 'sent' => is_numeric($row->sent) ? (int) $row->sent : 0, 'spend' => $row->spend];
        }

        return $out;
    }
}
