<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

/**
 * EC-11: a provider POLICY REFUSAL on benign content (over-refusal) is a
 * distinct category — mapped by the caller to a no_grounding-style handoff,
 * logged as `provider_refusal` for the vendor review, and NEVER surfaced raw
 * to a customer. Detection: the OpenAI-shape finish_reason `content_filter`
 * plus refusal-shaped prose where the model ignored the JSON contract.
 */
final class RefusalDetector
{
    private const PATTERNS = [
        '/\b(i can(?:no|\')t|i cannot|i am unable to|i\'m unable to|i won\'t)\b[^.]{0,60}\b(help|assist|comply|provide|answer)/iu',
        '/\b(against|violates?)\b[^.]{0,40}\b(policy|policies|guidelines|terms)\b/iu',
        '/\bas an ai (language )?model\b[^.]{0,60}\b(cannot|can\'t|unable)/iu',
    ];

    public function isPolicyRefusal(ProviderResponse $response): bool
    {
        $choices = $response->raw['choices'] ?? null;
        if (is_array($choices) && is_array($choices[0] ?? null) && ($choices[0]['finish_reason'] ?? null) === 'content_filter') {
            return true;
        }

        $content = $response->content ?? '';
        if ($content === '' || json_decode($content, true) !== null) {
            return false; // JSON that parses is the contract honored, not a refusal
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return true;
            }
        }

        return false;
    }
}
