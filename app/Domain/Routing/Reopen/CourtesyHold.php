<?php

declare(strict_types=1);

namespace App\Domain\Routing\Reopen;

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Routing\Record\RoutingDecisionRecord;
use App\Domain\Routing\Rescue\RescueTimers;
use App\Domain\Routing\Rescue\SystemReturn;
use App\Models\Ticket;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Doc 11 EC-08 (A-54) — "Reopened ticket with previous assignee now Away → waits for them
 * per routing.reopen.wait_for_previous (default 15 min) then re-routes; the record shows
 * the courtesy hold."
 *
 * Doc 06 FR-08 keeps an ACTIVE previous assignee on the row at reopen (ReopenTicket's
 * reconcile); when that agent is not Online, THIS writes the hold: a `routing_decisions`
 * row (trigger `reopened`, outcome `waiting`, `record.hold_until` + `hold_for`) — INSERT-only,
 * the truth of the hold — and arms the `hold` timer on the RescueTimers ZSET with the
 * decision id (A-54: a reuse of the pattern, not a new sweep species). The sweep re-checks
 * against that row: agent Online → released (nothing to do, they have it); still assigned
 * and still not Online → the `SystemReturn` port (P-C6, 9b) hands it back and Doc 11's
 * unassigned trigger re-routes it.
 */
final class CourtesyHold
{
    public const KIND = 'hold';

    public function __construct(
        private readonly TenantContext $context,
        private readonly SettingsRepository $settings,
        private readonly RescueTimers $timers,
        private readonly AgentStatus $status,
        private readonly EventPublisher $events,
        private readonly Container $container,
    ) {}

    public function minutes(): int
    {
        $m = $this->settings->get('routing.reopen.wait_for_previous');

        return is_numeric($m) ? max(1, (int) $m) : 15;
    }

    /** ticket.reopened with a retained assignee: hold when they are not Online. Returns the decision id or null. */
    public function onReopened(Ticket $ticket): ?string
    {
        $assignee = $ticket->assignee_id;
        if (! is_string($assignee)) {
            return null;
        }
        $current = $this->status->current($assignee);
        if ($current['status'] === 'online') {
            return null;
        }
        $until = $this->timers->now() + $this->minutes() * 60;
        $record = new RoutingDecisionRecord($this->context, $this->events, $ticket->identifier(), $ticket->conversation_id, 'reopened');
        $record->step('courtesy_hold', ['hold_for' => $assignee, 'assignee_status' => $current['status'], 'minutes' => $this->minutes()], sprintf('previous assignee is %s — holding %d min for them before re-routing (EC-08)', $current['status'], $this->minutes()));
        $record->snapshot(['hold_until' => $until, 'hold_for' => $assignee]);
        $queueId = $ticket->getAttribute('queue_id');
        $departmentId = $ticket->getAttribute('department_id');
        $id = $record->close('waiting', is_string($queueId) ? $queueId : null, is_string($departmentId) ? $departmentId : null, null);
        $this->timers->arm($this->context->tenantId(), self::KIND, $id, $until);

        return $id;
    }

    /** The sweep's visit for a hold decision id. */
    public function visit(string $decisionId): string
    {
        /** @var object{ticket_id: string, snapshot: string}|null $row */
        $row = DB::table('routing_decisions')->where('id', $decisionId)->where('trigger', 'reopened')->where('outcome', 'waiting')->first(['ticket_id', 'snapshot']);
        if ($row === null) {
            $this->timers->disarm($this->context->tenantId(), self::KIND, $decisionId);

            return 'dropped';
        }
        $snapshot = (array) json_decode($row->snapshot, true);
        $holdFor = $snapshot['hold_for'] ?? null;
        $ticket = Ticket::query()->find($row->ticket_id);
        if (! $ticket instanceof Ticket || ! is_string($holdFor) || $ticket->assignee_id !== $holdFor) {
            $this->timers->disarm($this->context->tenantId(), self::KIND, $decisionId);

            return 'released';   // reassigned or resolved meanwhile — the hold is moot
        }
        if ($this->status->current($holdFor)['status'] === 'online') {
            $this->timers->disarm($this->context->tenantId(), self::KIND, $decisionId);

            return 'released';   // they came back: the ticket stays theirs
        }
        if (! $this->container->bound(SystemReturn::class)) {
            Log::warning('EC-08 hold expired: SystemReturn door unbound — re-route deferred', ['ticket_id' => $ticket->identifier(), 'metric' => 'routing.reopen.hold_deferred']);

            return 'deferred';
        }
        $this->container->make(SystemReturn::class)->returnToQueue($ticket, 'reopen:hold_expired');
        $this->timers->disarm($this->context->tenantId(), self::KIND, $decisionId);

        return 'returned';
    }
}
