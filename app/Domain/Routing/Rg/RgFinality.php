<?php

declare(strict_types=1);

namespace App\Domain\Routing\Rg;

/**
 * Doc 11 FR-08 step 2 / BR-02 / SEC-06 / US-06 — RG routing is FINAL.
 *
 * "RG override: rg intent/flag (Doc 09 FR-13, Doc 08 FR-15) forces the is_rg
 * queue — final, step 3 boosts cannot demote it." BR-02: "no rule, boost,
 * strategy, or manual bulk action may divert RG-flagged intake from the RG queue
 * (individual manual reassign by RG-permitted staff excepted, audited)."
 *
 * This is the ONE decision function every path consults. It has exactly one
 * exception and it is spelled out as narrowly as the document draws it:
 * `Diversion::IndividualManualReassign` by an actor holding the RG-permitted
 * capability. Everything else — rules, boosts, strategies, bulk actions, AI
 * suggestions, transfers by rule — is refused. RgFinalityTest's table is the
 * proof; sabotage #3 (a boost allowed to move an RG ticket) goes red there.
 */
final class RgFinality
{
    /** @param  list<string>  $actorPermissions */
    public function mayDivert(bool $ticketIsRg, Diversion $diversion, array $actorPermissions = []): bool
    {
        if (! $ticketIsRg) {
            return true;
        }

        return $diversion === Diversion::IndividualManualReassign
            && in_array('supervision.reassign', $actorPermissions, true)
            && in_array('customers.rg_flag.view', $actorPermissions, true);
    }

    /** Step 2: an RG intake resolves to the tenant's is_rg queue when one exists. */
    public function forcedQueue(bool $isRgIntake, ?string $rgQueueId, string $resolvedQueueId): string
    {
        return ($isRgIntake && $rgQueueId !== null) ? $rgQueueId : $resolvedQueueId;
    }
}
