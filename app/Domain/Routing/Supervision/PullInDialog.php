<?php

declare(strict_types=1);

namespace App\Domain\Routing\Supervision;

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Membership\EffectiveMembers;
use App\Domain\Routing\Capacity\CapacityResolver;
use App\Domain\Routing\Temporary\PullIn;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 §8 / FR-15 / BR-07 (S11 step 13) — the PULL-IN DIALOG data contract (B5-S02 C03/C04/C05
 * seam; rendering is Doc 15's). The dialog's truth: the queue's context line, candidate agents
 * OUTSIDE the queue's effective membership (ids + status + load — names resolve at render),
 * the expiry defaulted to SHIFT END (FR-15/A-53 via HoursContract) and the BR-07 recurrence
 * hint per candidate. The commit door stays `PullIn::create` (SEC-01 guards live there).
 */
final class PullInDialog
{
    public function __construct(
        private readonly EffectiveMembers $members,
        private readonly AgentStatus $status,
        private readonly CapacityResolver $capacity,
        private readonly PullIn $pullIn,
    ) {}

    /** @return array{queue_id: string, waiting: int, eligible_online: int, candidates: list<array{user_id: string, status: string, load: int, capacity: int, recurrence_hint: ?string}>, default_expires_at: string} */
    public function payload(string $queueId, int $waitingDepth, int $eligibleOnline): array
    {
        $memberIds = $this->members->eligible($queueId);
        /** @var list<string> $others */
        $others = DB::table('users')->whereNotIn('id', $memberIds)->whereNull('disabled_at')->orderBy('id')->limit(50)->pluck('id')->all();
        $candidates = [];
        foreach ($others as $userId) {
            $load = $this->capacity->loadFor($userId, $queueId);
            $candidates[] = [
                'user_id' => $userId,
                'status' => $this->status->current($userId)['status'],
                'load' => $load['load'],
                'capacity' => $load['capacity'],
                'recurrence_hint' => $this->pullIn->recurrenceHint($queueId, $userId),
            ];
        }

        return [
            'queue_id' => $queueId,
            'waiting' => $waitingDepth,
            'eligible_online' => $eligibleOnline,
            'candidates' => $candidates,
            'default_expires_at' => $this->pullIn->defaultExpiry($queueId)->format(DATE_ATOM),
        ];
    }
}
