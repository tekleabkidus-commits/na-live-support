<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A credential did not resolve to a usable tenant (Doc 01 SEC-06).
 *
 * EXTENDS NotFoundHttpException, SO IT RENDERS AS 404. That is the requirement, and
 * the reasoning is worth keeping next to the code: 403 means "this exists and you
 * may not have it", which confirms existence. Given a list of candidate widget keys
 * or bot tokens, an attacker who can tell 403 from 404 can enumerate which tenants
 * are real. So every failure — unknown key, revoked key, wrong kind, tenant not
 * found — produces the same 404 with the same body.
 *
 * The message deliberately carries no detail about WHY. Detail goes to the log with
 * the security metric; the response says only that there is nothing here.
 */
final class TenantNotResolved extends NotFoundHttpException
{
    public static function forSource(ResolutionSource $source): self
    {
        // Identical response for every cause and every source. A message that varied
        // by source would leak which credential types exist.
        return new self('Not Found');
    }
}
