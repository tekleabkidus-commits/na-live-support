<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

use RuntimeException;

/** A gateway/route door refused the input with a stable refusal code (C-04's shape). */
final class GatewayRefused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(public readonly string $refusalCode, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
