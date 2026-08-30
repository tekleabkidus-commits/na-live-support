<?php

declare(strict_types=1);

namespace App\Domain\Routing\Rescue;

use App\Domain\Inbox\Status\AgentStatus;
use App\Models\Ticket;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-16a — "heartbeat-lost agent (Doc 07 FR-28) with active assigned open tickets →
 * after routing.rescue.minutes (default 10) tickets flagged in the Rescue view and — if
 * routing.rescue.auto — returned to queue with a system note".
 *
 * ARMED on `agent.status_changed{reason: heartbeat_lost}` (one timer per agent, deadline =
 * DB now + minutes), DISARMED when the agent is online again; the sweep's visit RE-CHECKS
 * under DB time: the agent still away/heartbeat_lost (AgentStatus::current materializes
 * a dead heartbeat) and each ticket still assigned to them and open-family — then FLAGS
 * (`RescueFlags`, one live flag per ticket) and, with `routing.rescue.auto`, RETURNS through
 * the `SystemReturn` port (P-C6, landed 9b: Doc 06's unassignBySystem behind SystemReturnViaDoc06). A supervisor's manual return (Reassign)
 * clears the flag through the projector's `ticket.assigned` recompute.
 */
final class HeartbeatRescue
{
    public const KIND = 'heartbeat';

    public function __construct(
        private readonly TenantContext $context,
        private readonly SettingsRepository $settings,
        private readonly RescueTimers $timers,
        private readonly RescueFlags $flags,
        private readonly AgentStatus $status,
        private readonly SystemReturn $return,
    ) {}

    public function minutes(): int
    {
        $m = $this->settings->get('routing.rescue.minutes');

        return is_numeric($m) ? max(1, (int) $m) : 10;
    }

    public function autoReturn(): bool
    {
        return (bool) $this->settings->get('routing.rescue.auto');
    }

    /** agent.status_changed: arm on heartbeat loss (only when they hold open tickets), disarm on return. */
    public function onStatusChanged(string $userId, string $status, ?string $reason): void
    {
        if ($status === 'online') {
            $this->timers->disarm($this->context->tenantId(), self::KIND, $userId);

            return;
        }
        if ($reason !== 'heartbeat_lost') {
            return;
        }
        if ($this->openTickets($userId) === []) {
            return;
        }
        $this->timers->arm($this->context->tenantId(), self::KIND, $userId, $this->timers->now() + $this->minutes() * 60);
    }

    /**
     * The sweep's visit.
     *
     * @return array{flagged: int, returned: int, outcome: string}
     */
    public function visit(string $userId): array
    {
        $current = $this->status->current($userId);
        if ($current['status'] === 'online') {
            $this->timers->disarm($this->context->tenantId(), self::KIND, $userId);

            return ['flagged' => 0, 'returned' => 0, 'outcome' => 'released'];
        }
        $tickets = $this->openTickets($userId);
        if ($tickets === []) {
            $this->timers->disarm($this->context->tenantId(), self::KIND, $userId);

            return ['flagged' => 0, 'returned' => 0, 'outcome' => 'nothing_held'];
        }
        $flagged = 0;
        $returned = 0;
        foreach ($tickets as $ticket) {
            if ($this->flags->live($ticket->identifier()) === null) {
                $this->flags->flag($ticket->identifier(), 'heartbeat_lost');
                $flagged++;
            }
            if (! $this->autoReturn()) {
                continue;
            }
            $this->return->returnToQueue($ticket, 'rescue:heartbeat_lost');
            $this->flags->clear($ticket->identifier(), autoReturned: true);
            $returned++;
        }
        $this->timers->disarm($this->context->tenantId(), self::KIND, $userId);   // flagged (and returned when auto): the timer's job is done

        return ['flagged' => $flagged, 'returned' => $returned, 'outcome' => $returned > 0 ? 'returned' : 'flagged'];
    }

    /** @return list<Ticket> */
    private function openTickets(string $userId): array
    {
        return array_values(Ticket::query()->where('assignee_id', $userId)
            ->whereIn('status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'])
            ->orderBy('id')->get()->all());
    }
}
