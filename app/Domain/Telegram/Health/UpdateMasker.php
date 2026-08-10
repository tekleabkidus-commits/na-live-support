<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Health;

/**
 * SEC-03/EC-12: the raw-update viewer's masking. Applied at VIEW time — the
 * stored payload keeps its shape ("the field stays visible so the payload
 * still makes sense", B6-S06 §B) and the masking holds for EVERY viewer,
 * including holders of telegram.health.view ("masked for everyone, including
 * you" — the frozen delivery note). There is no unmask parameter BY DESIGN.
 *
 * Masked:
 * - phone-number FIELDS (`phone_number`, `phone`) wherever they appear;
 * - the entire `contact` payload's values (SEC-03: "contact payloads");
 * - forwarded-origin author fields (EC-12): `forward_origin`, `forward_from`,
 *   `forward_from_chat`, `forward_sender_name` — the "forwarded message"
 *   annotation survives because the KEY survives;
 * - phone-shaped VALUES in any string field (defence in depth — a number
 *   typed into free text is still a number).
 */
final class UpdateMasker
{
    public const MASKED = '[masked]';

    /** Field names masked wherever they appear, at any depth. */
    private const MASKED_FIELDS = ['phone_number', 'phone'];

    /** Subtrees whose VALUES are masked wholesale, keys kept. */
    private const MASKED_SUBTREES = ['contact', 'forward_origin', 'forward_from', 'forward_from_chat'];

    /** Scalar fields masked outright. */
    private const MASKED_SCALARS = ['forward_sender_name', 'forward_date'];

    /**
     * @param  array<array-key, mixed>  $update
     * @return array<array-key, mixed>
     */
    public function mask(array $update): array
    {
        return $this->walk($update);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function walk(array $node): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, self::MASKED_FIELDS, true)) {
                $out[$key] = self::MASKED;

                continue;
            }
            if (is_string($key) && in_array($key, self::MASKED_SCALARS, true)) {
                $out[$key] = self::MASKED;

                continue;
            }
            if (is_string($key) && in_array($key, self::MASKED_SUBTREES, true)) {
                $out[$key] = is_array($value) ? $this->maskAllValues($value) : self::MASKED;

                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->walk($value);

                continue;
            }
            if (is_string($value) && $this->looksLikePhone($value)) {
                $out[$key] = self::MASKED;

                continue;
            }
            if (is_string($value)) {
                // A phone typed INSIDE free text is still a phone (SEC-03):
                // mask the shaped substring, keep the rest of the sentence.
                $out[$key] = $this->maskEmbeddedPhones($value);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $subtree
     * @return array<array-key, mixed>
     */
    private function maskAllValues(array $subtree): array
    {
        $out = [];
        foreach ($subtree as $key => $value) {
            $out[$key] = is_array($value) ? $this->maskAllValues($value) : self::MASKED;
        }

        return $out;
    }

    /** A phone-shaped value: 7+ digits allowing +, spaces, dashes, parens. */
    private function looksLikePhone(string $value): bool
    {
        return preg_match('/^\+?[\d\s\-().]{7,20}$/', trim($value)) === 1
            && preg_match_all('/\d/', $value) >= 7;
    }

    /** Replace phone-shaped runs (≥7 digits, separators allowed) inside text. */
    private function maskEmbeddedPhones(string $value): string
    {
        return (string) preg_replace_callback(
            '/\+?\d[\d\s\-().]{4,18}\d/',
            static fn (array $m): string => preg_match_all('/\d/', $m[0]) >= 7 ? self::MASKED : $m[0],
            $value,
        );
    }
}
