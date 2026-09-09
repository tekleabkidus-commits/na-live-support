<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

/** T-02 `capabilities() → {inbound_stop, alphanumeric_sender, max_throughput}`. */
final readonly class Capabilities
{
    public function __construct(
        public bool $inboundStop,
        public bool $alphanumericSender,
        public int $maxThroughput,
    ) {}
}
