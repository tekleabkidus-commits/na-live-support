<?php

declare(strict_types=1);

namespace App\Domain\Customers\Rg;

use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Support\Facades\DB;

/**
 * The Customer tab badge's ONE brain (escalation #3 part 2, ruled): the dot
 * means exactly one thing — *the active thread's customer carries an RG flag
 * AND this viewer holds customers.rg_flag.view* (B4-S05 E01, the single
 * drawn dot state). E03 is the contract's sharp edge: for an unpermitted
 * viewer the answer is FALSE — absence — because the dot itself would be
 * BR-03's inference channel. Blocked is NOT a dot state (no frame draws it;
 * §8's red banner is the drawn signal — the ruling's decision 2, recorded
 * here and in the frame-mapping row).
 *
 * Reads ride RgFlagRepository — the NFR-06 no-cache path binds this read
 * exactly as it binds every other RG read. Both callers use this class:
 * WorkspaceController::tabBadges() (the initial render / deep link) and the
 * badge door (client-side thread switches) — one derivation, two doors.
 */
final class CustomerTabBadge
{
    public function __construct(
        private readonly RgFlagRepository $rg,
        private readonly CapabilityMapProvider $verdicts,
    ) {}

    /** The conversation's customer id — the panel's fetch anchor (customers.view-gated at the doors). */
    public function customerFor(?string $conversationId): ?string
    {
        if ($conversationId === null) {
            return null;
        }
        $customerId = DB::table('conversations')->where('id', $conversationId)->value('customer_id');

        return is_string($customerId) ? $customerId : null;
    }

    public function for(?string $conversationId, User $viewer): bool
    {
        $customerId = $this->customerFor($conversationId);
        if ($customerId === null) {
            return false;
        }
        // E03 FIRST: no verdict, no dot — and no RG read either (the columns
        // stay untouched for the unpermitted, SEC-01's query-level posture).
        if (! $this->verdicts->mapFor($viewer)->allows('customers.rg_flag.view')) {
            return false;
        }

        $current = $this->rg->current($customerId);

        return $current !== null && $current->rg_flag;
    }
}
