<?php

declare(strict_types=1);

namespace App\Domain\Org;

use RuntimeException;

/**
 * Doc 11 EC-10 / FR-01 / FR-03 / FR-18 — a structure write refused at the SERVICE door
 * (SCHEMA AMENDMENT 5 FLAG A: the DB keeps default_queue_id nullable; the writer is the
 * net). Carries a stable problem-details code (C-04).
 */
final class OrgRefused extends RuntimeException
{
    public function __construct(public readonly string $refusalCode, string $detail)
    {
        parent::__construct(sprintf('%s: %s', $refusalCode, $detail));
    }
}
