# S11 STOP-A PACKET — Doc 06 core edit: `AssignTicket::assignBySystem` + `PlaceInQueue` (PROPOSED; countersign BEFORE landing)

Ruling of record: STOP-A APPROVED IN DIRECTION (2026-08-30) with obligations: system path carries FR-15's
as-if-assigned check (A-11) exactly as the staff path; actor=system, audited; AC-03's zero-over-capacity
concurrent proof rides the packet; RoutingNeverWritesTicketsTest lands with a positive control (LANDED at
step 1 — `S11-step1/sabotage-5-RED-01/-RESTORED-01.txt`); Doc 06's own suites green; A-49 filed (DRAFTED
in ANSWERS, commits at 2b).

## 1. `app/Domain/Tickets/Assignment/AssignTicket.php` — ONE new public door, `write()` reused

```diff
 final class AssignTicket
 {
     public function __construct(
         private readonly TicketScope $scope,
         private readonly TicketTransitionService $transitions,
         private readonly CapabilityMapProvider $verdicts,
         private readonly SettingsRepository $settings,
         private readonly EventPublisher $events,
         private readonly AuditLogger $audit,
+        private readonly AssignmentCapacity $capacity,   // Doc 11 step 4: the FOR UPDATE commit-time check
     ) {}
 
+    /**
+     * Doc 11 T-02 / A-49 — THE SYSTEM DOOR. Routing's only way to place work on a
+     * person. Same FR-15 as-if-assigned scope check as the staff doors (A-11: Own is
+     * satisfied by the assignment; Team/Dept must match the ticket's department — real
+     * now that Doc 11 binds membership); same FR-16 race guard (the UPDATE … WHERE
+     * assignee_id IS NULL IS the race — a manual claim landing first wins, BR-03);
+     * same FR-05 new → open through the transition service, with
+     * TransitionContext::system(). PLUS the Doc 11 FR-05/EC-01/EC-03 commit-time
+     * capacity check INSIDE this transaction: the agent's agent_assignment_stats row
+     * is locked FOR UPDATE and the count re-read; over capacity → AtCapacity, the
+     * row untouched, and the dispatcher moves to the next candidate. Zero
+     * over-capacity assignments is therefore serialized by the database, not by a
+     * read (AC-03's concurrent proof: CapacityRaceTest).
+     *
+     * Audited as ticket.assigned with actorType=System, actorId=null and the
+     * routing decision id in the payload (BR-06: every automatic decision explains
+     * itself). No permission check — TransitionActor::System's own rule: the path is
+     * server-internal and unreachable by any request.
+     */
+    public function assignBySystem(Ticket $ticket, User $assignee, RoutingCommit $commit): Ticket
+    {
+        return $this->write($ticket, $assignee, null, claimOnly: true, commit: $commit);
+    }
+
-    private function write(Ticket $ticket, User $assignee, User $actor, bool $claimOnly): Ticket
+    private function write(Ticket $ticket, User $assignee, ?User $actor, bool $claimOnly, ?RoutingCommit $commit = null): Ticket
     {
         if (! $this->verdicts->verdictFor($assignee, 'tickets.view')->allowed
             || ! $this->scope->visibleOnceAssignedTo($assignee, $ticket)) {
             throw new AssigneeOutOfScope($assignee->identifier());
         }
 
         $previous = $ticket->assignee_id;
 
-        return DB::transaction(function () use ($ticket, $assignee, $actor, $claimOnly, $previous): Ticket {
+        return DB::transaction(function () use ($ticket, $assignee, $actor, $claimOnly, $previous, $commit): Ticket {
+            if ($commit !== null) {
+                // Doc 11 FR-05/EC-01/EC-03: the capacity check that SERIALIZES the race —
+                // SELECT … FOR UPDATE on the agent's stats row inside THIS transaction.
+                $this->capacity->reserveOrThrow($assignee, $commit);   // throws AtCapacity
+            }
             $query = Ticket::query()->whereKey($ticket->identifier());
             if ($claimOnly) {
                 $query->whereNull('assignee_id');
             }
             $written = $query->update([...unchanged...]);
             if ($written !== 1) { ...unchanged (same-person noop; AlreadyClaimed)... }
             $ticket->refresh();
-            $this->record($ticket, $previous, $assignee->identifier(), $actor);
+            $this->record($ticket, $previous, $assignee->identifier(), $actor, $commit);
             if ($ticket->status() === TicketStatus::New) {
-                $this->transitions->transition($ticket, TicketStatus::Open, TransitionContext::staff($actor));
+                $this->transitions->transition(
+                    $ticket,
+                    TicketStatus::Open,
+                    $actor === null ? TransitionContext::system('routing') : TransitionContext::staff($actor),
+                );
                 $ticket->refresh();
             }
             return $ticket;
         });
     }
 
-    private function record(Ticket $ticket, ?string $old, ?string $new, User $actor): void
+    private function record(Ticket $ticket, ?string $old, ?string $new, ?User $actor, ?RoutingCommit $commit = null): void
     {
         $this->events->publish('ticket.assigned', [
             ...unchanged keys...
-            'by' => $actor->identifier(),
+            'by' => $actor?->identifier(),          // null = the system (catalog keys unchanged; value nullable)
         ]);
         $this->audit->record(
             actionKey: 'ticket.assigned',
             entity: 'ticket',
             entityId: $ticket->identifier(),
-            actorType: AuditActor::Staff,
+            actorType: $actor === null ? AuditActor::System : AuditActor::Staff,
-            payload: [ 'ticket_id' => …, 'old_assignee_id' => $old, 'new_assignee_id' => $new ],
+            payload: [ 'ticket_id' => …, 'old_assignee_id' => $old, 'new_assignee_id' => $new ]
+                + ($commit === null ? [] : ['routing_decision_id' => $commit->decisionId]),
-            actorId: $actor->identifier(),
+            actorId: $actor?->identifier(),
         );
     }
 }
```
`RoutingCommit` (Doc 11-owned value object: `decisionId`, `queueId`, `capacity` snapshot) and
`AssignmentCapacity` (Doc 11-owned, `reserveOrThrow()` = `SELECT … FOR UPDATE` on
`agent_assignment_stats` + the FR-06 count rule) are INJECTED into Doc 06's door — Doc 06 gains a
dependency on an interface Doc 11 implements (`App\Domain\Tickets\Assignment\AssignmentCapacity`,
an interface in Doc 06's tree; Doc 11 binds `RoutingCapacity`). A null-object binding keeps every
existing Doc 06 test green without Doc 11 present.

## 2. `registries/audit-coverage.php` — `ticket.assigned` gains the system actor
```diff
         'action_key' => 'ticket.assigned',
         'entity' => 'ticket',
-        'actor_types' => ['staff'],
+        'actor_types' => ['staff', 'system'],   // Doc 11 T-02/A-49: assignBySystem
```
The event catalog's `ticket.assigned` payload keys are UNCHANGED (`by` becomes nullable in value);
the `fixtures/tickets/consumers/doc11.json` read-slice already names `new_assignee_id`.

## 3. NEW `app/Domain/Tickets/Placement/PlaceInQueue.php` (Doc 06 tree — the queue_id writer)
```php
final class PlaceInQueue
{
    /** actor null = routing (system); a User = a staff transfer/queue change (Doc 06 FR-22's queue half). */
    public function place(Ticket $ticket, string $queueId, ?User $actor, string $reason): Ticket
    {
        return DB::transaction(function () use ($ticket, $queueId, $actor, $reason): Ticket {
            Ticket::query()->whereKey($ticket->identifier())->update([
                'queue_id' => $queueId,                       // queued_at is stamped by the migrator trigger (amendment 5 FLAG G)
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $ticket->refresh();
            $this->events->publish('ticket.waiting', [ 'tenant_id' => …, 'ticket_id' => …, 'queue' => $queueId, 'position' => null ]);
            // position is filled by the dispatcher's ZSET rank in the SAME event family; null here = "placed, not yet ranked"
            return $ticket;
        });
    }
}
```
`TransferTicket` (FR-22) gains ONE call to `PlaceInQueue::place()` when a transfer names a queue
(today it changes `department_id` only) — a second, smaller Doc 06 edit inside this packet.

## 4. Proofs riding the packet
- `tests/Feature/Tickets/AssignTicketTest` (Doc 06's own) — unchanged and green.
- NEW `tests/Feature/Routing/CapacityRaceTest.php` (AC-03): N parallel `assignBySystem` calls
  against one agent with capacity C → exactly C succeed, the rest `AtCapacity`, zero over-capacity
  (the STOP-A obligation); `AssigneeOutOfScope` on a Team-scoped assignee outside the department
  (A-11 real).
- `StatusWriterEncapsulationTest` + the 13th gate: green (status still written only by the service).
- `RoutingNeverWritesTicketsTest`: green (both new writers live in Doc 06's tree, by design).
