<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Sets and clears `app.tenant_id` on the database session (Doc 01 T-01 layer 2,
 * R-01, EC-01).
 *
 * This class is the single most dangerous piece of code in the project, and it is
 * worth being explicit about why.
 *
 * Every RLS policy reads `current_setting('app.tenant_id')`. That is a SESSION
 * variable, and under Octane a session is a long-lived PDO connection reused by
 * thousands of requests for different tenants. Set it and forget to clear it, and
 * the next request on that connection inherits the previous tenant's visibility —
 * with the application layer none the wiser, because Eloquent's scope would be
 * filtering for the *correct* tenant while the database silently permitted the
 * *previous* one. That is R-01, rated the highest-impact risk in Doc 01, and it
 * fails in the direction of showing one operator another operator's data.
 *
 * Three properties defend against it:
 *
 *   1. `set()` is called after resolution; `clear()` is called at request/job
 *      teardown in a `finally`, so an exception cannot skip it.
 *   2. `clear()` sets the value to the EMPTY STRING rather than resetting it.
 *      A subsequent query then fails with `invalid input syntax for type uuid`
 *      instead of returning rows. Fail-closed AND loud: a leak becomes a 500 on
 *      the request that leaked, not a silent cross-tenant read weeks later.
 *   3. Reconnection is handled: if PDO drops and re-establishes mid-request the
 *      variable is gone, and TenancyServiceProvider re-applies it on the
 *      ConnectionEstablished event. Without that, a reconnect would produce the
 *      loud failure in (2) on a perfectly legitimate request.
 *
 * `set_config(..., false)` — not `true` — because `true` scopes the value to the
 * current TRANSACTION. Most work here happens outside an explicit transaction, and
 * a transaction-local value would evaporate at the first commit, mid-request.
 *
 * EC-01's test asserts a fresh connection sees this empty. Never "optimise" the
 * clear away because the next request will set it anyway: the window between
 * teardown and the next resolution is precisely where a leak lives.
 */
final class RlsSession
{
    /**
     * The RLS session variable. Named in one place because the policies in
     * database/migrations/*_enable_row_level_security.php spell the same string,
     * and tests/Isolation/RlsCoverageTest.php asserts the two agree.
     */
    public const VARIABLE = 'app.tenant_id';

    public function set(string $tenantId, ?ConnectionInterface $connection = null): void
    {
        $this->apply($tenantId, $connection);
    }

    /**
     * Clear the variable, leaving it DEFINED-BUT-EMPTY (see the class docblock).
     */
    public function clear(?ConnectionInterface $connection = null): void
    {
        $this->apply('', $connection);
    }

    /**
     * Read the variable back, as the database sees it.
     *
     * Uses the missing_ok form so a genuinely unset variable reads as null rather
     * than raising — this is the diagnostic path, and a diagnostic that throws
     * cannot report the state it was asked about. The POLICIES use the strict
     * form on purpose (see the RLS migration).
     */
    public function current(?ConnectionInterface $connection = null): ?string
    {
        $connection ??= DB::connection();

        /** @var array<int, object{value: string|null}> $rows */
        $rows = $connection->select(sprintf("SELECT current_setting('%s', true) AS value", self::VARIABLE));

        return $rows[0]->value ?? null;
    }

    private function apply(string $value, ?ConnectionInterface $connection): void
    {
        $connection ??= DB::connection();

        // Bound as a parameter, never interpolated: this value arrives from
        // resolution and interpolating it would be an injection point into the
        // one statement that decides what every other statement can see.
        $connection->statement(sprintf("SELECT set_config('%s', ?, false)", self::VARIABLE), [$value]);
    }
}
