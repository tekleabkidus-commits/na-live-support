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
 * its countersign (LANDED at 9b: `SystemReturnViaDoc06`, bound in AppServiceProvider).
 */
interface SystemReturn
{
    /** `$decisionId` — the hold's own record for EC-08; null = write a `rescue` record first (FR-16a). */
    public function returnToQueue(Ticket $ticket, string $reason, ?string $decisionId = null): Ticket;
}
