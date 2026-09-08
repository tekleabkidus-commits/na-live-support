<?php

declare(strict_types=1);

namespace App\Domain\Sms\State;

/**
 * §9's state chip as data: the glyph name the design system draws (B1-DS-v2's
 * row; `double_check` and `moon` are the D14-D01 class-2 fills), the i18n key
 * (N-03 — the chip's word is never a literal), and the tone token family.
 */
final readonly class Chip
{
    public function __construct(
        public string $glyph,
        public string $i18nKey,
        public string $tone,
    ) {}
}
