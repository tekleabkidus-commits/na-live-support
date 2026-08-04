<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * FR-08's health() → {status, detail}. FS-01 turns transitions of this into
 * `channel.health.changed` events (A-4) that Doc 05/13 surfaces consume.
 */
final class AdapterHealth
{
    private function __construct(
        public readonly HealthStatus $status,
        public readonly string $detail,
    ) {}

    public static function ok(string $detail = ''): self
    {
        return new self(HealthStatus::Ok, $detail);
    }

    public static function degraded(string $detail): self
    {
        return new self(HealthStatus::Degraded, $detail);
    }

    public static function down(string $detail): self
    {
        return new self(HealthStatus::Down, $detail);
    }
}
