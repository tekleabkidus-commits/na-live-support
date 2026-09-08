<?php

declare(strict_types=1);

namespace App\Domain\Sms\Guards;

/**
 * Doc 14 T-06 / FR-03 / AC-01 — THE GUARD FUNCTION: a pure, ORDERED truth
 * table over (customer, event, template, time) returning proceed | hold |
 * skip{reason}. "The module's correctness heart alongside routing" (T-06).
 *
 * The order IS the contract (FR-03, cited as written):
 *   A-67 pre-guards: feature disabled → skip; hold-all-sends → HOLD; no gateway → skip;
 *   1. no phone identity → skip;
 *   2. opted-out → skip (ABSOLUTE — FR-14/BR-03: "even security notifications respect opt-out");
 *   3. customer blocked (Doc 08 FR-18) → skip except security_notification;
 *   4. RG-flagged → skip all except window_reengagement and ticket_resolved
 *      (Doc 08 FR-15 — essential service continuity only);
 *   4b. marketing-suppressed (Doc 08 FR-15's reader contract) → the same essential set (A-67);
 *   5. duplicate (same event + ticket + phone within the window) → skip;
 *   6. quiet hours (FR-16; FR-03 writes "FR-12") → hold or skip per the template's policy.
 *
 * No constructor, no dependencies, no clock: `GuardTruthTableTest` pins that
 * by reflection and by source. Every branch is a row in that table.
 */
final class GuardFunction
{
    public function evaluate(GuardInput $input): GuardDecision
    {
        /** @var list<array{check: string, result: string}> $log */
        $log = [];
        $pass = static function (string $check) use (&$log): void {
            $log[] = ['check' => $check, 'result' => 'pass'];
        };
        $stop = static function (string $check, GuardOutcome $outcome, GuardReason $reason) use (&$log): GuardDecision {
            $log[] = ['check' => $check, 'result' => $outcome->value];

            return new GuardDecision($outcome, $reason, $log);
        };

        // ── A-67 pre-guards
        if (! $input->module->featureEnabled) {
            return $stop('feature_enabled', GuardOutcome::Skip, GuardReason::FeatureDisabled);
        }
        $pass('feature_enabled');

        if ($input->module->holdAllSends) {
            return $stop('hold_all_sends', GuardOutcome::Hold, GuardReason::HoldAllSends);
        }
        $pass('hold_all_sends');

        if (! $input->module->gatewayAvailable) {
            return $stop('gateway_available', GuardOutcome::Skip, GuardReason::NoGateway);
        }
        $pass('gateway_available');

        // ── FR-03 #1
        if (! $input->customer->hasPhone) {
            return $stop('has_phone', GuardOutcome::Skip, GuardReason::NoPhone);
        }
        $pass('has_phone');

        // ── FR-03 #2 — absolute; no event is excepted
        if ($input->customer->optedOut) {
            return $stop('opted_out', GuardOutcome::Skip, GuardReason::OptedOut);
        }
        $pass('opted_out');

        // ── FR-03 #3
        if ($input->customer->blocked && ! $input->event->exemptFromBlocked()) {
            return $stop('blocked', GuardOutcome::Skip, GuardReason::Blocked);
        }
        $pass('blocked');

        // ── FR-03 #4
        if ($input->customer->rgFlagged && ! $input->event->essentialServiceContinuity()) {
            return $stop('rg_flagged', GuardOutcome::Skip, GuardReason::RgFlagged);
        }
        $pass('rg_flagged');

        // ── FR-03 #4b (A-67)
        if ($input->customer->marketingSuppressed && ! $input->event->essentialServiceContinuity()) {
            return $stop('marketing_suppressed', GuardOutcome::Skip, GuardReason::MarketingSuppressed);
        }
        $pass('marketing_suppressed');

        // ── FR-03 #5 — "within 10 min": strictly inside the window
        if ($input->lastSameSendAt !== null) {
            $elapsedSeconds = $input->now->getTimestamp() - $input->lastSameSendAt->getTimestamp();
            if ($elapsedSeconds >= 0 && $elapsedSeconds < $input->duplicateWindowMinutes * 60) {
                return $stop('duplicate', GuardOutcome::Skip, GuardReason::Duplicate);
            }
        }
        $pass('duplicate');

        // ── FR-03 #6 (FR-16)
        if ($input->inQuietHours) {
            $decision = match ($input->template->quietHours) {
                QuietHoursPolicy::Exempt => null,
                QuietHoursPolicy::Hold => $stop('quiet_hours', GuardOutcome::Hold, GuardReason::QuietHours),
                QuietHoursPolicy::Skip => $stop('quiet_hours', GuardOutcome::Skip, GuardReason::QuietHours),
            };
            if ($decision !== null) {
                return $decision;
            }
        }
        $pass('quiet_hours');

        return new GuardDecision(GuardOutcome::Proceed, null, $log);
    }
}
