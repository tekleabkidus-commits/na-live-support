<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Designer;

use RuntimeException;

/** A refused publish — the code names the rule, `extra` carries the inline figures (the contrast ratio and the suggested shade). */
final class ConfigRefused extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(public readonly string $refusalCode, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
