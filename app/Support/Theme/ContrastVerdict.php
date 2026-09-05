<?php

declare(strict_types=1);

namespace App\Support\Theme;

/** A-62: one contrast verdict — the WCAG 2.x ratio and the two AA thresholds (4.5:1 text, 3:1 large text / UI). */
final readonly class ContrastVerdict
{
    public function __construct(
        public float $ratio,
        public bool $passesAa,
        public bool $passesAaLarge,
    ) {}

    /** @return array{ratio: float, passes_aa: bool, passes_aa_large: bool} */
    public function toArray(): array
    {
        return ['ratio' => round($this->ratio, 2), 'passes_aa' => $this->passesAa, 'passes_aa_large' => $this->passesAaLarge];
    }
}
