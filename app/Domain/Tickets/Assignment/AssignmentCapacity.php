<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Assignment;

use App\Models\User;

/**
 * Doc 11 FR-05/FR-06/EC-01/EC-03 — the commit-time capacity check, as an interface in
 * Doc 06's tree (S11 STOP-A packet, countersigned 2026-08-30).
 *
 * AssignTicket::assignBySystem() calls reserveOrThrow() INSIDE its transaction, before
 * the guarded UPDATE: the implementation locks the agent's stats row (SELECT … FOR
 * UPDATE) and re-reads the open-assigned count, so two dispatchers racing for the same
 * agent are serialized by the database — the second sees the first's increment and
 * throws AtCapacity, leaving the ticket row untouched (EC-03: "no error surfaces", the
 * dispatcher moves to the next candidate). Doc 11 binds RoutingCapacity; Doc 06 owns
 * only the call site and the contract.
 */
interface AssignmentCapacity
{
    /** @throws AtCapacity when the assignee has no free slot under the queue's capacity rule */
    public function reserveOrThrow(User $assignee, RoutingCommit $commit): void;
}
