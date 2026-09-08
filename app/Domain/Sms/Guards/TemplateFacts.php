<?php

declare(strict_types=1);

namespace App\Domain\Sms\Guards;

/** The template half of T-06's input: the published template's quiet-hours policy (FR-04). */
final readonly class TemplateFacts
{
    public function __construct(
        public QuietHoursPolicy $quietHours,
    ) {}
}
