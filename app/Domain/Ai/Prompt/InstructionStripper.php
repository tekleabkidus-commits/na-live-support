<?php

declare(strict_types=1);

namespace App\Domain\Ai\Prompt;

/**
 * T-03's INDEX-TIME sanitizer: removes imperative-to-assistant patterns from
 * KB text before it enters kb_chunks (sabotage #3's subject — disabling this
 * lets a planted imperative reach the prompt and the red-team fixture goes
 * red). Runs at INDEX time, never query time, so a poisoned article is
 * defused once, and the chunk's provenance records how many patterns were
 * stripped (the T-03 provenance requirement).
 *
 * BROAD PATTERN + the same allowlist philosophy as the scrubber: legitimate
 * KB prose ("follow these steps to deposit") survives — the patterns target
 * ASSISTANT-directed imperatives (addressing "you, the assistant/AI/model/
 * system" or naming prompt/instruction mechanics), not user-directed help
 * text. Over-stripping loses a sentence of KB text; under-stripping is an
 * injection channel — the trade leans safe.
 */
final class InstructionStripper
{
    private const PATTERNS = [
        // "ignore/disregard/forget (your|all|previous|the) instructions/rules/prompt..."
        '/[^.!?\n]*\b(ignore|disregard|forget|override)\b[^.!?\n]{0,60}\b(instructions?|rules?|prompts?|system)\b[^.!?\n]*[.!?]?/iu',
        // Direct address to the assistant/model/AI with an imperative payload.
        '/[^.!?\n]*\b(you are now|act as|pretend to be|as an? (ai|assistant|model)|dear (ai|assistant|model))\b[^.!?\n]*[.!?]?/iu',
        // Prompt-mechanics vocabulary that has no place in customer-facing KB prose.
        '/[^.!?\n]*\b(system prompt|developer message|jailbreak|do anything now)\b[^.!?\n]*[.!?]?/iu',
        // Tool/action coercion: "call/execute/run the ... action/tool/function".
        '/[^.!?\n]*\b(call|execute|run|invoke)\b[^.!?\n]{0,40}\b(action|tool|function)\b[^.!?\n]*[.!?]?/iu',
    ];

    /** @return array{content: string, stripped: int} */
    public function strip(string $content): array
    {
        $stripped = 0;

        foreach (self::PATTERNS as $pattern) {
            $content = (string) preg_replace_callback(
                $pattern,
                static function () use (&$stripped): string {
                    $stripped++;

                    return '';
                },
                $content,
            );
        }

        return ['content' => trim((string) preg_replace('/[ \t]{2,}/', ' ', $content)), 'stripped' => $stripped];
    }
}
