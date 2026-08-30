<?php

declare(strict_types=1);

namespace App\Domain\Routing\Rescue;

use App\Models\Ticket;

/**
 * Doc 11 FR-16a / EC-08 — THE PORT for "returned to queue" / "then re-routes": a SYSTEM
 * unassignment of an assigned ticket. Doc 06 owns every ticket write (the 13th gate); its
 * `AssignTicket` has staff doors only (`unassign()` demands a staff actor holding
 * `tickets.assign`). The implementation is the Doc 06 core-edit packet P-C6 (S11 step 9
 * STOP: `AssignTicket::unassignBySystem(Ticket, string $reason)` — the same `record()`,
 * `ticket.assigned{new: null, by: null}`, audited as system) bound here at step 9b under
 * its countersign. Until then no binding exists: the auto-return and hold-expiry legs
 * refuse loudly (`SystemReturnUnavailable`), the flags and the records still land.
 */
interface SystemReturn
{
    public function returnToQueue(Ticket $ticket, string $reason): Ticket;
}
