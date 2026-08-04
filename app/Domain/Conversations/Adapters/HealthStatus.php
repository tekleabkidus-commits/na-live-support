<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';
}
