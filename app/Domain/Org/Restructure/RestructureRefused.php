<?php

declare(strict_types=1);

namespace App\Domain\Org\Restructure;

use RuntimeException;

final class RestructureRefused extends RuntimeException
{
    public function __construct(public readonly string $refusalCode, string $detail)
    {
        parent::__construct(sprintf('%s: %s', $refusalCode, $detail));
    }
}
