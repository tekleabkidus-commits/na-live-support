<?php

declare(strict_types=1);

namespace App\Domain\Customers\Blocking;

use Illuminate\Support\Facades\DB;

/**
 * T-06's SECOND BELT — the customer-level guard Doc 04's compose door asks on
 * EVERY outbound (the ruled core edit, escalation #2): a fresh single-column
 * read, no cache, so a conversation created MID-CASCADE is refused the
 * moment its customer is blocked, and a crashed cascade cannot open a gap
 * (FS-04 — this guard is the thing that holds). SABOTAGE #8 removes the
 * compose door's call and AC-06's mid-cascade clause goes red.
 */
final class CustomerSendGuard
{
    public function refuses(string $customerId): bool
    {
        return (bool) DB::table('customers')->where('id', $customerId)->value('blocked');
    }
}
