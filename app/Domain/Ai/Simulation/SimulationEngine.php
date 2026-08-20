<?php

declare(strict_types=1);

namespace App\Domain\Ai\Simulation;

use App\Domain\Ai\Pipeline\RoutePolicy;
use Illuminate\Support\Facades\DB;

/**
 * FR-11/US-06/AC-03/BR-07: HISTORICAL SIMULATION — "with these values,
 * yesterday's conversations would have been X% auto-resolved / Y% more
 * handoffs", computed from the decision log through the SAME RoutePolicy
 * the live pipeline applied (parity by construction; the AC-03 test proves
 * it against a live replay). §12: one day (10k decisions) ≤ 5 s — the query
 * is a single day-window scan on the (tenant, created_at) index.
 */
final class SimulationEngine
{
    /**
     * @param  array<string, float>  $thresholds
     * @return array{total: int, outcomes: array<string, int>, rg_fires: int, token: string}
     */
    public function simulateDay(string $dayStartIso, array $thresholds, ?string $endpointRef = null): array
    {
        $query = DB::table('ai_decisions')
            ->where('task', 'classify_message')
            ->whereRaw('created_at >= ?::timestamptz', [$dayStartIso])
            ->whereRaw("created_at < ?::timestamptz + interval '1 day'", [$dayStartIso]);

        if ($endpointRef !== null) {
            $query->where('endpoint_ref', $endpointRef);
        }

        $outcomes = ['rg_override' => 0, 'spam' => 0, 'handoff_low_confidence' => 0, 'eligible_auto_resolve' => 0, 'eligible' => 0];
        $total = 0;

        foreach ($query->orderBy('created_at')->cursor() as $row) {
            $response = json_decode(is_string($row->response_payload) ? $row->response_payload : '', true);
            if (! is_array($response) || ! is_array($response['classification'] ?? null)) {
                continue; // only genuine classification replays — the RG override's own row is an OUTCOME, not an input
            }
            $classification = $response['classification'];
            $spam = is_numeric($classification['spam'] ?? null) ? (float) $classification['spam'] : 0.0;

            $outcome = RoutePolicy::outcomeFor(
                is_numeric($row->confidence) ? (float) $row->confidence : 0.0,
                is_numeric($row->rg_risk) ? (float) $row->rg_risk : 0.0,
                $spam,
                $thresholds,
            );
            $outcomes[$outcome]++;
            $total++;
        }

        return [
            'total' => $total,
            'outcomes' => $outcomes,
            'rg_fires' => $outcomes['rg_override'],
            // BR-07's evidence token: publishing thresholds requires the
            // simulation that was RUN for exactly these values.
            'token' => hash('sha256', json_encode([$dayStartIso, $thresholds, $endpointRef], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * BR-07: the publish door must present the token of a simulation run for THESE values.
     *
     * @param  array<string, mixed>  $thresholds
     */
    public static function tokenFor(string $dayStartIso, array $thresholds, ?string $endpointRef = null): string
    {
        return hash('sha256', json_encode([$dayStartIso, $thresholds, $endpointRef], JSON_THROW_ON_ERROR));
    }
}
