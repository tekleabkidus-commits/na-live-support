<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * FR-08's sender{external_user_id, display, username?, phone?}. This is what the core
 * hands the Doc 08 identity-resolver SEAM (FR-10 step 3) — staff-collected data, never
 * `verified` (invariant N-06).
 */
final class InboundSender
{
    public function __construct(
        public readonly string $externalUserId,
        public readonly string $display,
        public readonly ?string $username = null,
        public readonly ?string $phone = null,
    ) {}
}
