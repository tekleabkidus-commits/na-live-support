<?php

declare(strict_types=1);

namespace App\Domain\Ai\Supervisor;

use App\Domain\Ai\KillSwitch\KillSwitches;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Providers\ProviderHealth;

/**
 * FS-01's "Supervisor tasks PAUSE" rung + FR-18's supervisor kill switch,
 * as one question every supervisor task asks before doing anything:
 * paused() returns the reason or null. Provider-down = every hop of the
 * tier the task would use reads DOWN in ProviderHealth (the step-8 ladder's
 * state; recovery flips it back, which IS the auto-resume — nothing to
 * un-pause). A paused run is recorded (decision row, provider none) so the
 * gap is visible in the feed, never silent.
 */
final class SupervisorGate
{
    public function __construct(
        private readonly KillSwitches $switches,
        private readonly ProviderHealth $health,
        private readonly AiOrchestrator $orchestrator,
    ) {}

    /** @return 'killswitch'|'provider_down'|null */
    public function paused(string $tier = 'fast'): ?string
    {
        if ($this->switches->isOff('supervisor', null, null)) {
            return 'killswitch';
        }

        $chain = $this->orchestrator->chainFor($tier);
        if ($chain === []) {
            return null;
        }
        foreach ($chain as $hop) {
            if ($this->health->current($hop['provider']) !== 'down') {
                return null; // at least one hop is not known-down
            }
        }

        return 'provider_down';
    }
}
