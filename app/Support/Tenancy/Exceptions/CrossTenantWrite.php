<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a model is saved carrying a tenant id other than the bound one
 * (Doc 01 T-01: "attempting to save with a mismatched tenant id throws").
 *
 * RLS would refuse this write anyway — the WITH CHECK clause on every policy
 * exists for exactly this — so why throw first? Because the two layers answer
 * different questions. RLS says "this write is not permitted" at the end of a
 * transaction, in SQL, to whoever reads the logs. This says "your code is
 * wrong", at the line that did it, with the two ids side by side. The database
 * is the guarantee; this is the diagnosis.
 *
 * A Sev-1 incident class per NFR-01 when it happens in production: it means some
 * path constructed a record for the wrong tenant, and the interesting question
 * is which path, not which row.
 */
final class CrossTenantWrite extends RuntimeException
{
    public static function forModel(string $model, string $attempted, string $bound): self
    {
        return new self(sprintf(
            'Refusing to save %s stamped with tenant %s while the bound tenant is %s (Doc 01 T-01). '
            .'A record is written for the tenant in context; tenant_id is never chosen by the caller. '
            .'Row-level security would also refuse this write — this exception exists to name the line '
            .'that built it.',
            $model,
            $attempted,
            $bound,
        ));
    }
}
