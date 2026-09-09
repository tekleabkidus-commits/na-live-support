<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

use App\Domain\Sms\State\DlrStatus;

/**
 * T-02 `parseDlr(payload) → {provider_id, status, raw}`: the normalized status
 * (the driver's mapping table; an unmapped word is Unknown — never guessed),
 * the provider id the gateway names, the RAW status word retained (FR-13), and
 * the carrier code where the gateway gives one.
 */
final readonly class ParsedDlr
{
    public function __construct(
        public ?string $providerId,
        public DlrStatus $status,
        public string $raw,
        public ?string $code,
    ) {}
}
