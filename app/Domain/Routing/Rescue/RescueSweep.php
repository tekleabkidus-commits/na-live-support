<?php

declare(strict_types=1);

namespace App\Domain\Routing\Rescue;

use App\Domain\Routing\Reopen\CourtesyHold;
use App\Support\Tenancy\RunsWithinTenant;

/**
 * Doc 11 FR-16a / EC-08 — the 1-minute deadline sweep behind `routing:rescue-sweep` (P-C3:
 * the ONE allowlist entry; a timer sweep in the Doc 06 shape, not a queue poll — the
 * dispatcher stays event-driven). Every visit re-checks under DB time inside the tenant.
 */
final class RescueSweep
{
    use RunsWithinTenant;

    public function __construct(
        private readonly RescueTimers $timers,
        private readonly HeartbeatRescue $heartbeat,
        private readonly CourtesyHold $hold,
    ) {}

    /** @return array<string, int> outcome counts */
    public function sweep(int $limit = 500): array
    {
        $counts = [];
        foreach ($this->timers->due($limit) as $member) {
            [$tenantId, $kind, $id] = $this->timers->parseMember($member);
            $outcome = (string) $this->withinTenant($tenantId, fn (): string => match ($kind) {
                HeartbeatRescue::KIND => $this->heartbeat->visit($id)['outcome'],
                CourtesyHold::KIND => $this->hold->visit($id),
                default => 'unknown',
            });
            if ($outcome === 'unknown') {
                $this->timers->disarm($tenantId, $kind, $id);
            }
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        return $counts;
    }
}
