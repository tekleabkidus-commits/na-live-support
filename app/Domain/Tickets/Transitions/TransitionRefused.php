<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Transitions;

use RuntimeException;

/**
 * A legal, permitted transition refused by an INVARIANT or a missing mandatory reason.
 *
 * The codes are stable strings (C-04) and each traces to a numbered requirement:
 *   reason_required            FR-04 — escalate, spam, staff reopen-of-closed
 *   reason_too_short           FR-23/BR-06 — escalation reasons are ≥ 10 characters
 *   channel_unreachable_hold   BR-07 — an unreachable customer's ticket is never
 *                              auto-resolved or auto-closed; a human decides
 *
 * The property is `refusalCode`, not `code`: PHP's Exception already declares a
 * non-readonly `$code`, and redeclaring it readonly is a fatal error (found on this
 * file's first run). Renaming is better than dropping `readonly` — these carry a
 * problem-details code that nothing should be able to rewrite after the throw.
 */
final class TransitionRefused extends RuntimeException
{
    public function __construct(public readonly string $refusalCode, string $detail)
    {
        parent::__construct(sprintf('%s: %s', $refusalCode, $detail));
    }
}
