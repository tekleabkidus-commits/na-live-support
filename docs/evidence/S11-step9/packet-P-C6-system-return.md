# S11 step 9 — PACKET P-C6 (escalate-first; countersign BEFORE landing): the SYSTEM RETURN door — Doc 06 core edit

## The boundary (mandatory stop 4)
Doc 11 FR-16a ("if routing.rescue.auto on — returned to queue with a system note") and EC-08 ("waits for them …
then re-routes") both need a SYSTEM unassignment of an ASSIGNED ticket. Doc 06 owns every ticket write (the 13th
gate): `AssignTicket::unassign(Ticket, User $actor)` demands a STAFF actor holding `tickets.assign`;
`AssigneeDeactivated::handBack(User)` carries deactivation semantics (`ticket.assignee_deactivated`) — reusing it
would lie to Doc 07/Doc 16 about why the ticket moved. No door exists. Step 9 lands everything else and declares the
PORT `App\Domain\Routing\Rescue\SystemReturn` (unbound: the auto-return and hold-expiry legs refuse LOUDLY —
`routing.rescue.return_deferred` / `routing.reopen.hold_deferred` metrics, outcome `deferred`, timer kept — proven
by RescueTest/ReopenHoldTest). This packet supplies the implementation.

## Proposed diff — `app/Domain/Tickets/Assignment/AssignTicket.php` (≤ 12 lines, one method, no existing line changes)
```php
    /**
     * Doc 11 FR-16a / EC-08 (S11 step 9, P-C6): THE SYSTEM RETURN — routing hands an ASSIGNED ticket back to its
     * queue as the system actor (rescue auto-return; a reopen courtesy hold that expired). The same record() as
     * unassign(): ticket.assigned{old, new: null, by: null}, audited as system; the unassigned trigger re-routes.
     * No permission check — TransitionActor::System's own rule; no route reaches it (AssignBySystemDoorTest's shape).
     */
    public function unassignBySystem(Ticket $ticket, string $reason): Ticket
    {
        $previous = $ticket->assignee_id;

        return DB::transaction(function () use ($ticket, $previous, $reason): Ticket {
            Ticket::query()->whereKey($ticket->identifier())->update(['assignee_id' => null, 'updated_at' => DB::raw('clock_timestamp()')]);
            $ticket->refresh();
            $this->record($ticket, $previous, null, null, reason: $reason);   // record() gains an optional reason slot in the payload
            return $ticket;
        });
    }
```
(`record()`'s signature: if it cannot carry `reason` without a change, the reason rides the routing record instead and the
call is `$this->record($ticket, $previous, null, null)` — ZERO changes to existing lines either way; the advisor picks.)

## The binding (Doc 11's side, step 9b)
`AppServiceProvider`: `SystemReturn::class` → an adapter `Routing/Rescue/SystemReturnViaDoc06` calling
`AssignTicket::unassignBySystem()` and, for the rescue case, `persistSystemMarker('routing_rescue_returned')` (the FR-16a
system note; i18n en/am) + `rescue.auto_returned{queue_id, previous_assignee_id}` + `routing.rescue.auto_returned` audit.

## Proofs riding the packet (9b)
`RescueTest` auto-return leg (flag → return → `rescue.auto_returned` → Rescue view LEAVES in both forms → re-routed by
the unassigned trigger), `ReopenHoldTest` expiry leg (still Away at the deadline → returned → re-routed; the record
shows the hold), `AssignBySystemDoorTest`-shaped unreachability proof for the new door, `RoutingNeverWritesTicketsTest`
unchanged (the write stays in Doc 06). Registry: `rescue.auto_returned` producer already declared (Doc11/Rescue).
