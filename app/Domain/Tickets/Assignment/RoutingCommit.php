<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Assignment;

/**
 * What routing hands Doc 06's system door (S11 STOP-A packet): the decision being
 * committed (FR-17's record id — audited on the assignment, BR-06), the queue the
 * ticket is leaving, and the capacity rule in force for that queue (FR-03/FR-06: the
 * queue's capacity_per_agent, or the tenant default; count_pending per the setting;
 * the per-user override applied by the capacity implementation).
 */
final readonly class RoutingCommit
{
    public function __construct(
        public string $decisionId,
        public string $queueId,
        public int $queueCapacity,
        public bool $countPending,
    ) {}
}
