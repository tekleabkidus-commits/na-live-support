<?php

declare(strict_types=1);

namespace App\Domain\Routing\Manual;

use App\Domain\Routing\Record\RoutingDecisionRecord;
use App\Domain\Routing\RoutingRefused;
use App\Domain\Routing\Waiting\WaitingSets;
use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Storage\TicketScope;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 FR-15 (manual reassign) / BR-03 / SEC-01 / SEC-06 / FR-17.
 *
 * "Reassign any in-scope ticket (drag or dialog; bypasses strategy, logged manual_by)."
 * No strategy is consulted, no eligibility, no capacity: BR-03 — humans override
 * machines, visibly. The assignment itself goes through Doc 06's `AssignTicket::assign`
 * door (FR-15's target-scope check, FR-05's new → open, `ticket.assigned`), so the
 * router writes no ticket row (the 13th gate). SEC-01 re-checks BOTH scopes: the
 * supervisor must hold view scope over the ticket (TicketScope::visible), the target
 * must hold it once assigned (the door's own `visibleOnceAssignedTo`).
 *
 * BR-03's "the router never fixes a supervisor's placement" is STRUCTURAL: every
 * automatic assignment is `UPDATE … WHERE assignee_id IS NULL` (claimOnly) — an
 * assigned ticket is invisible to the dispatcher, and its waiting-set member is removed
 * here after the commit (FS-02's order) so the mirror does not drift.
 *
 * The record: trigger=manual, outcome=manual, `manual_by` in the step — the timeline
 * expandable's "assigned by Dawit" (FR-17). SEC-06: when the ticket's queue is the RG
 * queue the audit entry is the HIGH-VISIBILITY class and only RG-permitted staff
 * (`customers.rg_flag.view`, RgFinality's own rule) may act — BR-02's one exception.
 */
final class Reassign
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TicketScope $scope,
        private readonly CapabilityMapProvider $maps,
        private readonly AssignTicket $assign,
        private readonly WaitingSets $waiting,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{record_id: string, old_assignee_id: ?string, new_assignee_id: string, from_queue_is_rg: bool} */
    public function reassign(Ticket $ticket, User $target, User $supervisor): array
    {
        if (! $this->scope->visible($supervisor, $ticket->identifier())) {
            throw new RoutingRefused('ticket_out_of_scope', 'the ticket lies outside the supervisor\'s scope (SEC-01)');
        }

        $queueId = $ticket->getAttribute('queue_id');
        $queueId = is_string($queueId) ? $queueId : null;
        $fromRg = $queueId !== null && (bool) DB::table('queues')->where('id', $queueId)->value('is_rg');
        if ($fromRg && ! $this->maps->verdictFor($supervisor, 'customers.rg_flag.view')->allowed) {
            throw new RoutingRefused('rg_staff_required', 'RG-queue tickets are moved by RG-permitted staff only (BR-02/SEC-06)');
        }

        $old = $ticket->assignee_id;
        $record = new RoutingDecisionRecord($this->context, $this->events, $ticket->identifier(), $ticket->conversation_id, 'manual');
        $record->step('manual_reassign', ['manual_by' => $supervisor->identifier(), 'old_assignee_id' => $old, 'new_assignee_id' => $target->identifier()], 'assigned by a supervisor — strategy bypassed (BR-03)');
        $record->strategy(null, null);

        $recordId = DB::transaction(function () use ($ticket, $target, $supervisor, $record, $queueId, $old, $fromRg): string {
            $this->assign->assign($ticket, $target, $supervisor);   // Doc 06's door: target scope, FR-05, ticket.assigned
            $departmentId = $ticket->fresh()?->getAttribute('department_id');
            $id = $record->close('manual', $queueId, is_string($departmentId) ? $departmentId : null, $target->identifier());
            $this->audit->record('routing.manual_reassign', 'ticket', $ticket->identifier(), AuditActor::Staff, [
                'ticket_id' => $ticket->identifier(), 'old_assignee_id' => $old, 'new_assignee_id' => $target->identifier(), 'from_queue_is_rg' => $fromRg, 'record_id' => $id,
            ], actorId: $supervisor->identifier());

            return $id;
        });

        if ($queueId !== null) {
            $this->waiting->remove($queueId, $ticket->identifier());   // FS-02: after the commit
        }

        return ['record_id' => $recordId, 'old_assignee_id' => $old, 'new_assignee_id' => $target->identifier(), 'from_queue_is_rg' => $fromRg];
    }
}
