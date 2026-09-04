<?php

declare(strict_types=1);

namespace App\Domain\Routing\Estimator;

use App\Domain\Routing\Hours\HoursContract;
use App\Domain\Routing\Waiting\WaitingSets;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-13 / FR-14 / T-06 / FS-03 / SEC-05 — the estimate for a queue (a new arrival,
 * position = depth + 1) or for ONE waiting ticket (its own position). The customer-facing
 * SHAPE is `{band, degraded}` and nothing else exists in it (SEC-05 by ABSENCE: no count,
 * no agent, no name is ever a key). Internal callers (Doc 15 later) may read `detail()`.
 *
 * FR-14: outside the department's hours (HoursContract, [D12]) the band is `off_hours`
 * with the next opening time — "when we open at HH:MM" — and eligibility is untouched
 * (EC-09: an Online agent at 18:01 is still assigned; hours never reach RoutableAgents).
 */
final class Estimator
{
    public function __construct(
        private readonly Counters $counters,
        private readonly BandMapper $mapper,
        private readonly WaitingSets $waiting,
        private readonly HoursContract $hours,
    ) {}

    /** @return array{band: string, degraded: bool} */
    public function forQueue(string $queueId, ?\DateTimeImmutable $now = null): array
    {
        $d = $this->detail($queueId, null, $now);

        return ['band' => $d['band'], 'degraded' => $d['degraded']];
    }

    /** @return array{band: string, degraded: bool} */
    public function forTicket(string $queueId, string $ticketId, ?\DateTimeImmutable $now = null): array
    {
        $d = $this->detail($queueId, $ticketId, $now);

        return ['band' => $d['band'], 'degraded' => $d['degraded']];
    }

    /**
     * The internal detail — NEVER returned to a customer surface.
     *
     * @return array{band: string, degraded: bool, position: int, throughput30: ?int, raw_minutes: float, opens_at: ?string}
     */
    public function detail(string $queueId, ?string $ticketId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= $this->hours->now();   // DB time (C-11); tests inject an instant — never assert on now()
        $nowEpoch = (int) $now->format('U');
        $departmentId = DB::table('queues')->where('id', $queueId)->value('department_id');
        $departmentId = is_string($departmentId) ? $departmentId : null;

        $own = $ticketId !== null ? $this->waiting->position($queueId, $ticketId) : null;   // 1-based already
        $position = $own ?? $this->waiting->depth($queueId) + 1;
        $throughput = $this->counters->throughput30($queueId, $nowEpoch);
        $degraded = $throughput === null;
        $raw = $this->mapper->rawMinutes($position, $throughput);

        if (! $this->hours->isOpen($departmentId, $now)) {
            $opens = $this->hours->nextOpen($departmentId, $now);

            return ['band' => 'off_hours', 'degraded' => $degraded, 'position' => $position, 'throughput30' => $throughput, 'raw_minutes' => $raw, 'opens_at' => $opens->format(\DateTimeInterface::ATOM)];
        }

        return ['band' => $this->mapper->shownBand($queueId, $raw, $nowEpoch), 'degraded' => $degraded, 'position' => $position, 'throughput30' => $throughput, 'raw_minutes' => $raw, 'opens_at' => null];
    }

    /**
     * Doc 13 T-05 (P-QPOS, countersigned 2026-09-04): the VISITOR surface's
     * queue card — band + the ticket's OWN 1-based position while it waits
     * unassigned, from the same detail() every staff figure reads. Consumed
     * ONLY server-side by the widget's sync envelope and its push consumer;
     * the staff estimate door keeps its two-key shape (SEC-05 —
     * EstimatorLeakageTest pins it, and pins that the staff door never
     * references this method). EC-06 is the WIDGET's rendering law over this
     * (the number never rises on screen); the server answers the truth.
     * A-58's constant is untouched.
     *
     * @return array{band: string, degraded: bool, position: ?int, opens_at: ?string}
     */
    public function forWaitingTicket(string $queueId, string $ticketId, ?\DateTimeImmutable $now = null): array
    {
        $d = $this->detail($queueId, $ticketId, $now);
        $own = $this->waiting->position($queueId, $ticketId);

        return ['band' => $d['band'], 'degraded' => $d['degraded'], 'position' => $own, 'opens_at' => $d['opens_at']];
    }
}
