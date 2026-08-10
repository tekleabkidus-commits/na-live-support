<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Health;

/**
 * FS-02's verdict for one bot at one poll:
 * - `broken` — pending_update_count GREW across polls while a normally-active
 *   bot heard nothing inbound past the silence threshold: Telegram cannot
 *   reach us. The one-click repair is re-register (idempotent; Telegram
 *   redelivers).
 * - `suspicious` — pending grew but the silence threshold has not yet passed
 *   (or inbound continued): degraded, watched, not alerted.
 *
 * A bot that was NEVER active does not alarm (the F12 empty state owns that
 * case) — "alert if a NORMALLY-ACTIVE bot is silent > threshold" (FS-02).
 */
final readonly class SilentWebhookVerdict
{
    public function __construct(
        public bool $broken,
        public bool $suspicious,
        public int $pendingCount,
    ) {}

    public static function evaluate(
        int $previousPending,
        int $currentPending,
        bool $normallyActive,
        bool $silencePastThreshold,
    ): self {
        $grew = $currentPending > $previousPending && $currentPending > 0;

        return new self(
            broken: $grew && $normallyActive && $silencePastThreshold,
            suspicious: $grew && ! ($normallyActive && $silencePastThreshold),
            pendingCount: $currentPending,
        );
    }
}
