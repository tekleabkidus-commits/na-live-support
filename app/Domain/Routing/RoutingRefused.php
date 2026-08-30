<?php

declare(strict_types=1);

namespace App\Domain\Routing;

use RuntimeException;

/**
 * A routing-family refusal with a STABLE code (C-04): the supervisor doors surface
 * `refusal_code` through ProblemDetails; the detail is developer-facing, never rendered.
 */
final class RoutingRefused extends RuntimeException
{
    public function __construct(public readonly string $refusalCode, string $detail)
    {
        parent::__construct(sprintf('%s: %s', $refusalCode, $detail));
    }
}
