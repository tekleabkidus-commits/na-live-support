<?php

declare(strict_types=1);

namespace App\Domain\Auth\Login;

use Illuminate\Support\Facades\DB;

/**
 * The application's ONLY pre-tenant path to a staff account (Doc 01 inherited
 * constraint #2; Doc 02 FR-01, SEC-01; ruling Q1).
 *
 * Everything that makes this safe lives in the database function it calls — see
 * the create_resolve_staff_login_function migration for why a SECURITY DEFINER
 * function owned by a NOLOGIN role is the answer, and tests/Isolation/
 * PreTenantLookupTest.php for the properties that are actually proven rather
 * than asserted in prose.
 *
 * This class adds exactly two things and deliberately nothing else:
 *
 *   1. Normalisation, in ONE place. `lower()` also happens inside the function,
 *      belt and braces, because an address normalised differently on two paths
 *      is how "the reset email went to a different account than the login" bugs
 *      are born.
 *   2. Row → value object mapping, so no caller ever handles a raw stdClass with
 *      a password digest on it.
 *
 * It performs NO password verification and makes NO decision about which
 * candidate is the account. That is StaffUserProvider's job, where the timing
 * equalisation AC-01 requires can be controlled.
 */
final class StaffLoginResolver
{
    /**
     * @return list<StaffLoginCandidate> every account matching this address, across tenants
     */
    public function candidatesFor(string $email): array
    {
        $normalised = $this->normalise($email);

        if ($normalised === '') {
            return [];
        }

        /** @var list<object> $rows */
        $rows = DB::select('SELECT * FROM resolve_staff_login(?)', [$normalised]);

        return array_map(StaffLoginCandidate::fromRow(...), $rows);
    }

    public function normalise(string $email): string
    {
        // Trim then lowercase. No further "cleverness" (no dot-stripping, no
        // plus-addressing collapse): those are provider-specific conventions,
        // and applying them would silently merge addresses the operator
        // considers distinct.
        return mb_strtolower(trim($email));
    }
}
