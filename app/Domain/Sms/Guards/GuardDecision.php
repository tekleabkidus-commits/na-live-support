<?php

declare(strict_types=1);

namespace App\Domain\Sms\Guards;

/**
 * T-06's output: proceed | hold | skip{reason} plus the ORDERED guard_log —
 * one entry per check evaluated, in FR-03 order, stopping at the first check
 * that holds or skips (T-01: `guard_log` jsonb on the message row; "each skip
 * logged with reason").
 */
final readonly class GuardDecision
{
    /**
     * @param  list<array{check: string, result: string}>  $log
     */
    public function __construct(
        public GuardOutcome $outcome,
        public ?GuardReason $reason,
        public array $log,
    ) {}
}
