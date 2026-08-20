<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chatbot;

use App\Domain\Ai\Prompt\ImmutableLayer;

/**
 * The OUTPUT-side leak check (T-03/AC-07's no-system-prompt-leakage clause):
 * a model answer that echoes system-layer text never reaches a customer —
 * the step-9 chatbot turn maps a flagged answer to a handoff, exactly like
 * an out-of-enum action. Detection is sentinel-substring based: distinctive
 * fragments of the immutable layer plus the layer's own fence vocabulary.
 * Cheap by design — this runs on every customer-visible answer.
 */
final class OutputGuard
{
    private const SENTINELS = [
        'PLATFORM HONESTY RULES',
        'NEVER identify yourself as',
        'IDENTITY (absolute)',
        '<<<customer_message>>>',
        '<<<chunk article_ref=',
    ];

    public function leaksSystemText(string $answer): bool
    {
        foreach (self::SENTINELS as $sentinel) {
            if (str_contains($answer, $sentinel)) {
                return true;
            }
        }

        // Any 60-char verbatim window of the immutable layer is a leak even
        // if it dodges the named sentinels.
        $normalized = preg_replace('/\s+/', ' ', $answer) ?? $answer;
        $layer = preg_replace('/\s+/', ' ', ImmutableLayer::TEXT) ?? ImmutableLayer::TEXT;
        $length = mb_strlen($layer);
        for ($offset = 0; $offset + 60 <= $length; $offset += 30) {
            if (str_contains($normalized, mb_substr($layer, $offset, 60))) {
                return true;
            }
        }

        return false;
    }
}
