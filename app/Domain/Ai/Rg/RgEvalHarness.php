<?php

declare(strict_types=1);

namespace App\Domain\Ai\Rg;

use RuntimeException;

/**
 * NFR-07/T-06's evaluation harness over the labeled corpus
 * (fixtures/ai/rg/labeled.json — the launch corpus; the governed-asset
 * process is Doc 19's, [D19]). Precision-first by design (BR-04): the
 * assertion is precision at the shipped threshold; recall is REPORTED,
 * never asserted (misses fall to human handling — the documented rationale).
 */
final class RgEvalHarness
{
    /** @return list<array{text: string, label: string, language: string}> */
    public function corpus(): array
    {
        $decoded = json_decode((string) file_get_contents(base_path('fixtures/ai/rg/labeled.json')), true);
        if (! is_array($decoded) || ! is_array($decoded['cases'] ?? null)) {
            throw new RuntimeException('the labeled RG corpus is missing or malformed — the harness would prove nothing');
        }

        $cases = [];
        foreach ($decoded['cases'] as $case) {
            if (is_array($case) && is_string($case['text'] ?? null) && is_string($case['label'] ?? null)) {
                $cases[] = [
                    'text' => $case['text'],
                    'label' => $case['label'],
                    'language' => is_string($case['language'] ?? null) ? $case['language'] : 'en',
                ];
            }
        }

        return $cases;
    }

    /**
     * @param  callable(string): float  $score  text -> rg_risk
     * @return array{threshold: float, tp: int, fp: int, fn: int, tn: int, precision: float, recall: float}
     */
    public function evaluate(callable $score, float $threshold): array
    {
        $tp = $fp = $fn = $tn = 0;

        foreach ($this->corpus() as $case) {
            $fires = $score($case['text']) >= $threshold;
            $isRisk = $case['label'] === 'risk';

            if ($fires && $isRisk) {
                $tp++;
            } elseif ($fires) {
                $fp++;
            } elseif ($isRisk) {
                $fn++;
            } else {
                $tn++;
            }
        }

        $precision = ($tp + $fp) === 0 ? 1.0 : $tp / ($tp + $fp);
        $recall = ($tp + $fn) === 0 ? 1.0 : $tp / ($tp + $fn);

        return [
            'threshold' => $threshold,
            'tp' => $tp, 'fp' => $fp, 'fn' => $fn, 'tn' => $tn,
            'precision' => $precision,
            'recall' => $recall,
        ];
    }
}
