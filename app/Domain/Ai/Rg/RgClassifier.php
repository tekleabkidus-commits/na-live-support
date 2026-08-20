<?php

declare(strict_types=1);

namespace App\Domain\Ai\Rg;

use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Pipeline\Thresholds;

/**
 * T-06: the RG classifier — SEPARATE prompt + config from general
 * classification, tuned for PRECISION (NFR-07/BR-04: flagging a healthy
 * customer is the costlier error; misses fall to human handling by design).
 *
 * THE TWO-STAGE SHAPE (this document's own design space, stated for the
 * record): general classification carries a cheap rg_risk SIGNAL; a signal
 * at or above the screening floor triggers THIS dedicated classifier —
 * its own prompt (below, versioned via the rg_classifier config kind when
 * an operator publishes one), its own invocation, precision-first wording.
 * T-06's separation is honored at the prompt/config/invocation level while
 * per-message cost stays bounded (most traffic never reaches stage two).
 * Threshold changes get the simulation view + precision readout (step 9's
 * engine + the eval harness here).
 */
final class RgClassifier
{
    public const TEMPLATE = <<<'TPL'
        You are a RESPONSIBLE-GAMBLING risk classifier for a betting-support platform. Precision matters more than recall: score high ONLY on clear evidence of gambling harm — distress about losses the person cannot afford, chasing losses, inability to stop, self-exclusion requests, gambling-linked crisis language. Complaints about service, delays, bonuses or bugs are NOT risk, whatever their anger. Return ONLY JSON: {"rg_risk": <0..1>, "evidence": "<ten words max, category not quote>"}
        TPL;

    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly Thresholds $thresholds,
    ) {}

    /**
     * @return array{rg_risk: float, screened: bool, tokens_in: int, tokens_out: int, provider: string, model: string}
     */
    public function confirm(string $customerText, float $screeningSignal, ?string $endpointRef = null): array
    {
        if ($screeningSignal < $this->thresholds->value('rg_screening_floor', $endpointRef)) {
            return ['rg_risk' => $screeningSignal, 'screened' => false, 'tokens_in' => 0, 'tokens_out' => 0, 'provider' => 'none', 'model' => 'none'];
        }

        $result = $this->orchestrator->execute('classify_message', [
            ['role' => 'system', 'content' => self::TEMPLATE],
            ['role' => 'user', 'content' => $customerText],
        ]);

        $decoded = json_decode((string) $result['response']->content, true);
        $risk = is_array($decoded) && is_numeric($decoded['rg_risk'] ?? null)
            ? max(0.0, min(1.0, (float) $decoded['rg_risk']))
            // An unparseable confirmation keeps the SCREENING signal — the
            // safe direction is a human look, not a silent zero.
            : $screeningSignal;

        return [
            'rg_risk' => $risk,
            'screened' => true,
            'tokens_in' => $result['response']->tokensIn,
            'tokens_out' => $result['response']->tokensOut,
            'provider' => $result['provider'],
            'model' => $result['model'],
        ];
    }
}
