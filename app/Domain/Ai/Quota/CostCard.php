<?php

declare(strict_types=1);

namespace App\Domain\Ai\Quota;

use App\Support\Settings\SettingsRepository;

/**
 * T-07: cost = tokens × the provider rate card (the ai.rate_card platform
 * setting — {provider: {input_per_1k, output_per_1k, currency}}). AC-10's
 * monthly reconciliation reads ai_decisions' tokens_in/out through this card;
 * the preamble overhead STEP 0 measured is metered as real cost, not assumed
 * away (ADR 0019).
 */
final class CostCard
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array{amount: float, currency: string} */
    public function costOf(string $provider, int $tokensIn, int $tokensOut): array
    {
        $card = $this->settings->get('ai.rate_card');
        $card = is_array($card) ? $card : [];
        $rates = is_array($card[$provider] ?? null) ? $card[$provider] : [];

        $in = is_numeric($rates['input_per_1k'] ?? null) ? (float) $rates['input_per_1k'] : 0.0;
        $out = is_numeric($rates['output_per_1k'] ?? null) ? (float) $rates['output_per_1k'] : 0.0;
        $currency = is_string($rates['currency'] ?? null) ? $rates['currency'] : 'ETB';

        return [
            'amount' => round(($tokensIn / 1000) * $in + ($tokensOut / 1000) * $out, 6),
            'currency' => $currency,
        ];
    }
}
