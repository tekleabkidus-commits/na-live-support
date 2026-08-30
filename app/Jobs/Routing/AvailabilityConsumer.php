<?php

declare(strict_types=1);

namespace App\Jobs\Routing;

use App\Domain\Routing\Dispatch\QueueDispatcher;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-08 step 5 / T-03 / NFR-02 — the AVAILABILITY-EVENT consumer, the first real
 * consumer of Doc 07's agent.status_changed (the [D11] seam on Doc 07 FR-28) and the
 * dispatcher's only trigger — no polling loop anywhere:
 *   agent.status_changed (status=online)      → the agent's queues
 *   ticket.status_changed (→ closed-family)   → the assignee's queues (a slot freed)
 *   ticket.assigned (new_assignee_id=null)     → the assignee's queues (handled by the unassigned
 *                                                router for the ticket; here the FREED slot)
 *   queue.membership_changed (added)          → that queue
 *   queue.temporary_grant.created             → that queue
 * IDEMPOTENT ON event_id: the dispatcher reads the waiting set and DB truth; a redelivery finds
 * nothing left to assign (or the same next ticket, which the guarded UPDATE serializes).
 * `automation` family (ADR 0007's precedent).
 */
final class AvailabilityConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(QueueDispatcher $dispatcher): void
    {
        /** @var object{id: string, name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first();
        if ($event === null) {
            return;
        }
        /** @var array<string, mixed> $payload */
        $payload = (array) json_decode($event->payload, true);
        foreach ($this->queuesFor($event->name, $payload) as $queueId) {
            $dispatcher->run($queueId);
        }
    }

    /** @param  array<string, mixed>  $payload
     * @return list<string> */
    private function queuesFor(string $name, array $payload): array
    {
        switch ($name) {
            case 'agent.status_changed':
                $userId = $payload['user_id'] ?? null;
                if (($payload['status'] ?? null) !== 'online' || ! is_string($userId)) {
                    return [];
                }

                return $this->agentQueues($userId);
            case 'ticket.status_changed':
                $ticketId = $payload['ticket_id'] ?? null;
                if (! in_array($payload['new_status'] ?? null, ['resolved', 'closed', 'spam'], true) || ! is_string($ticketId)) {
                    return [];
                }
                $assignee = DB::table('tickets')->where('id', $ticketId)->value('assignee_id');

                return is_string($assignee) ? $this->agentQueues($assignee) : [];
            case 'ticket.assigned':
                $old = $payload['old_assignee_id'] ?? null;
                if (($payload['new_assignee_id'] ?? null) !== null || ! is_string($old)) {
                    return [];
                }

                return $this->agentQueues($old);
            case 'queue.membership_changed':
                $queueId = $payload['queue_id'] ?? null;

                return ($payload['change'] ?? null) === 'added' && is_string($queueId) ? [$queueId] : [];
            case 'queue.temporary_grant.created':
                $queueId = $payload['queue_id'] ?? null;

                return is_string($queueId) ? [$queueId] : [];
            default:
                return [];
        }
    }

    /**
     * Every live queue the agent is an effective member of (direct, temporary, via team).
     *
     * @return list<string>
     */
    private function agentQueues(string $userId): array
    {
        /** @var list<string> $direct */
        $direct = DB::table('queue_members')->where('user_id', $userId)->whereNull('ended_at')
            ->where(static function (\Illuminate\Database\Query\Builder $q): void {
                $q->where('kind', 'direct')->orWhereRaw('expires_at > clock_timestamp()');
            })->pluck('queue_id')->all();
        /** @var list<string> $viaTeam */
        $viaTeam = DB::table('queue_teams as qt')->join('team_members as tm', 'tm.team_id', '=', 'qt.team_id')->where('tm.user_id', $userId)->pluck('qt.queue_id')->all();

        return array_values(array_unique([...$direct, ...$viaTeam]));
    }
}
