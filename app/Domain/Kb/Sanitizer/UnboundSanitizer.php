<?php

declare(strict_types=1);

namespace App\Domain\Kb\Sanitizer;

/**
 * S10 step 4 ONLY — the contract's placeholder until step 5 binds the shared
 * package. It flags NOTHING and says so loudly: AC-05 (the shared fixture suite)
 * is RED against this class by design, which is exactly sabotage #7's target
 * ("sanitizer stubbed") once step 5 has landed. Never ship this binding past step 5.
 */
final class UnboundSanitizer implements PublishSanitizer
{
    public function scan(array $blocks, string $language): array
    {
        return [];
    }
}
