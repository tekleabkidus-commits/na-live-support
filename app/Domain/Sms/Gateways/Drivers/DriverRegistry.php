<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways\Drivers;

use App\Domain\Sms\Gateways\DriverContract;
use App\Models\SmsGateway;

/**
 * T-02: the driver KIND is data on the gateway row (`driver`), mapped to code
 * here — two kinds in v1 (`http_generic`, `smpp_sidecar`); a named aggregator
 * driver joins by amendment (§19). No channel word is branched on (N-20).
 */
final class DriverRegistry
{
    public function __construct(
        private readonly HttpGenericDriver $httpGeneric,
        private readonly SmppSidecarDriver $sidecar,
    ) {}

    public function for(SmsGateway $gateway): DriverContract
    {
        return match ($gateway->driver) {
            'http_generic' => $this->httpGeneric,
            'smpp_sidecar' => $this->sidecar,
            default => throw new \LogicException(sprintf('unknown sms driver kind "%s" — the CHECK should make this impossible', $gateway->driver)),
        };
    }
}
