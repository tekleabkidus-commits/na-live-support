<?php

declare(strict_types=1);

namespace App\Domain\Sms\State;

/**
 * The result of applying one DLR to one state (FR-13): the state after,
 * whether the row moved, whether the receipt CONTRADICTS the record (EC-04 —
 * retained raw and flagged for the gateway-quality report, [D15]), and a
 * short annotation the `raw_dlrs` entry carries.
 */
final readonly class DlrOutcome
{
    public function __construct(
        public SmsDeliveryState $state,
        public bool $applied,
        public bool $contradiction,
        public ?string $annotation,
    ) {}
}
