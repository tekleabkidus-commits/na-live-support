<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

/**
 * Doc 12 FR-03/BR-02 — one action arm. Arms consume OWNING DOORS only and
 * return honest outcomes; they never throw for a business refusal (a refusal
 * IS the outcome — AC-01's isolation), only for infrastructure failure.
 */
interface AutomationAction
{
    /**
     * @param  array<string, mixed>  $action  the configured action card
     * @return array<string, mixed> {action, outcome, reason?, superseded_state?}
     */
    public function run(array $action, string $executionId, int $automationDepth): array;
}
