<?php

declare(strict_types=1);

namespace App\Domain\Routing\Rescue;

use App\Domain\Conversations\Storage\MessageRepository;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;

/**
 * Doc 11 FR-16c / EC-06 — "orphaned tickets (queue deleted mid-flight) auto-move to
 * department default with a system note". On `org.queue.deleted`: every UNASSIGNED
 * open-family ticket still naming the deleted queue is FLAGGED `orphaned` (Rescue view,
 * P-C1), then re-routed through the engine with trigger `orphan` and the department
 * explicit — the department default queue is FR-08 step 2's own fallback, the placement
 * is Doc 06's PlaceInQueue door (actor null = routing), and the record explains the move
 * (FR-17). On a placement the flag CLEARS (auto_returned) and `routing.orphan_moved` is
 * audited; the system note is `persistSystemMarker('routing_orphan_moved')` (N-03: a code,
 * rendered by i18n). A parked outcome (FS-04) leaves the flag live — the Rescue view keeps
 * it visible, never lost. Assigned tickets keep their agent (nothing to rescue) and only
 * lose the dangling queue reference on their next placement.
 */
final class OrphanRescue
{
    public function __construct(
        private readonly RescueFlags $flags,
        private readonly RoutingPipeline $pipeline,
        private readonly MessageRepository $messages,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{moved: int, parked: int} */
    public function onQueueDeleted(string $deletedQueueId): array
    {
        $tickets = Ticket::query()->where('queue_id', $deletedQueueId)->whereNull('assignee_id')
            ->whereIn('status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'])
            ->orderBy('id')->get()->values()->all();
        $moved = 0;
        $parked = 0;
        foreach ($tickets as $ticket) {
            $this->flags->flag($ticket->identifier(), 'orphaned');
            $departmentId = is_string($ticket->department_id) ? $ticket->department_id : null;
            $result = $this->pipeline->route($ticket, 'orphan', ['explicit_department_id' => $departmentId]);
            if ($result['outcome'] === 'parked_error' || ! is_string($result['queue_id'])) {
                $parked++;

                continue;
            }
            $conversation = Conversation::query()->find($ticket->conversation_id);
            if ($conversation instanceof Conversation) {
                $this->messages->persistSystemMarker($conversation, 'routing_orphan_moved', ['deleted_queue_id' => $deletedQueueId, 'moved_to_queue_id' => $result['queue_id']]);
            }
            $this->audit->record('routing.orphan_moved', 'ticket', $ticket->identifier(), AuditActor::System, [
                'ticket_id' => $ticket->identifier(), 'deleted_queue_id' => $deletedQueueId, 'default_queue_id' => $result['queue_id'],
            ]);
            $this->flags->clear($ticket->identifier(), autoReturned: true);
            $moved++;
        }

        return ['moved' => $moved, 'parked' => $parked];
    }
}
