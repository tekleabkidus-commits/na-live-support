<?php

declare(strict_types=1);

namespace App\Domain\Ai\Orchestrator;

use InvalidArgumentException;

/**
 * FR-01's task catalog — the twelve v1 tasks, CLOSED (matching
 * ai_decisions' task CHECK; both grow only by migration + amendment).
 * Each declares tier (fast = classification-grade, strong =
 * drafting-grade), max_tokens, timeout, retries, and whether output is
 * customer-visible (the stricter guardrail set — FR-01's own axis).
 */
final class TaskCatalog
{
    /** @var array<string, array{tier: string, max_tokens: int, timeout_s: int, retries: int, customer_visible: bool}> */
    public const TASKS = [
        'classify_message' => ['tier' => 'fast', 'max_tokens' => 200, 'timeout_s' => 10, 'retries' => 1, 'customer_visible' => false],
        'chatbot_turn' => ['tier' => 'strong', 'max_tokens' => 800, 'timeout_s' => 20, 'retries' => 1, 'customer_visible' => true],
        'draft_reply' => ['tier' => 'strong', 'max_tokens' => 600, 'timeout_s' => 20, 'retries' => 1, 'customer_visible' => false],
        'improve_text' => ['tier' => 'strong', 'max_tokens' => 600, 'timeout_s' => 15, 'retries' => 1, 'customer_visible' => false],
        'summarize_thread' => ['tier' => 'fast', 'max_tokens' => 400, 'timeout_s' => 15, 'retries' => 1, 'customer_visible' => false],
        'translate' => ['tier' => 'strong', 'max_tokens' => 800, 'timeout_s' => 15, 'retries' => 1, 'customer_visible' => true],
        'transcribe_voice' => ['tier' => 'fast', 'max_tokens' => 1000, 'timeout_s' => 60, 'retries' => 2, 'customer_visible' => false],
        'ocr_receipt' => ['tier' => 'fast', 'max_tokens' => 400, 'timeout_s' => 60, 'retries' => 2, 'customer_visible' => false],
        'generate_subject' => ['tier' => 'fast', 'max_tokens' => 60, 'timeout_s' => 10, 'retries' => 1, 'customer_visible' => false],
        'qa_prescore' => ['tier' => 'fast', 'max_tokens' => 300, 'timeout_s' => 30, 'retries' => 1, 'customer_visible' => false],
        'daily_digest' => ['tier' => 'strong', 'max_tokens' => 800, 'timeout_s' => 60, 'retries' => 1, 'customer_visible' => false],
        'anomaly_scan' => ['tier' => 'fast', 'max_tokens' => 400, 'timeout_s' => 60, 'retries' => 1, 'customer_visible' => false],
    ];

    /** @return array{tier: string, max_tokens: int, timeout_s: int, retries: int, customer_visible: bool} */
    public static function definition(string $task): array
    {
        if (! isset(self::TASKS[$task])) {
            throw new InvalidArgumentException(sprintf('unknown AI task "%s" — the catalog is closed (FR-01)', $task));
        }

        return self::TASKS[$task];
    }
}
