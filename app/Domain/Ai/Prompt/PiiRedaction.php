<?php

declare(strict_types=1);

namespace App\Domain\Ai\Prompt;

/**
 * SEC-02: PII minimization IN PROMPTS — phone/email/id numbers masked by
 * this layer unless the task needs them (OCR reference numbers are needed;
 * the exemption is per-task and explicit). The MASKING MAP is returned and
 * logged (decision meta), so an operator can see WHAT was masked without
 * seeing the values. Sabotage #13's subject.
 *
 * The patterns are the SEC-03 scrubber's own (one shape vocabulary across
 * the platform); the difference is placement — the scrubber guards sinks,
 * this guards EGRESS — and mechanics: masking is positional
 * ([masked:phone-1]) so the model can still refer to "the phone number you
 * sent" without ever seeing it.
 */
final class PiiRedaction
{
    private const PATTERNS = [
        'email' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,}/u',
        'phone' => '/\+\d{1,3}(?:[\s-]?\d){6,12}(?!\d)/',
        'phone_local' => '/(?<!\d)0[79](?:[\s-]?\d){8}(?!\d)/',
    ];

    /**
     * @param  bool  $keepReferences  the per-task exemption (ocr_receipt)
     * @return array{text: string, map: array<string, string>} masked text + the map (label => kind, never the value)
     */
    public function mask(string $text, bool $keepReferences = false): array
    {
        if ($keepReferences) {
            return ['text' => $text, 'map' => []];
        }

        $map = [];
        $counters = [];

        foreach (self::PATTERNS as $kind => $pattern) {
            $text = (string) preg_replace_callback(
                $pattern,
                static function () use (&$map, &$counters, $kind): string {
                    $shortKind = $kind === 'phone_local' ? 'phone' : $kind;
                    $counters[$shortKind] = ($counters[$shortKind] ?? 0) + 1;
                    $label = sprintf('[masked:%s-%d]', $shortKind, $counters[$shortKind]);
                    $map[$label] = $shortKind;

                    return $label;
                },
                $text,
            );
        }

        return ['text' => $text, 'map' => $map];
    }
}
