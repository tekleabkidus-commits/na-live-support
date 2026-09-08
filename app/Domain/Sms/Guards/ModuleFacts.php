<?php

declare(strict_types=1);

namespace App\Domain\Sms\Guards;

/**
 * A-67's module-level facts: the `sms.enabled` feature flag (Doc 16 FR-11:
 * "SMS OFF = guards skip with feature_disabled reason per Doc 14"), the
 * `sms.hold_all_sends` setting (B6-S16's "Hold all sends" — a pause that
 * preserves state, never a drop → HELD), and whether an active gateway exists
 * (§8: "SMS is off until a gateway exists").
 */
final readonly class ModuleFacts
{
    public function __construct(
        public bool $featureEnabled,
        public bool $holdAllSends,
        public bool $gatewayAvailable,
    ) {}
}
