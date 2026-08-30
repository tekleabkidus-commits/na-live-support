<?php

declare(strict_types=1);

namespace App\Domain\Routing\Priority;

use App\Support\Settings\SettingsRegistry;
use DateTimeImmutable;

/**
 * Doc 11 FR-12 / NFR-06 / BR-01 — the age-cap guarantee, as a rule OUTSIDE the score.
 *
 * "any ticket waiting > routing.starvation.minutes (default 30) jumps to queue
 * head regardless of boosts (no starvation, ever)". NFR-06: "the age-cap boost is
 * exempt from every other rule". BR-01: "the starvation cap overrides every
 * strategy and boost".
 *
 * A-51 (S11): the cap is NOT a weight in the effective-priority score — a weight
 * can be out-stacked by enough boosts. It is a separate, first-evaluated rule
 * that PROMOTES a starved ticket to the head; the score only orders the rest.
 * The VALUE comes from the settings registry (`routing.starvation.minutes`,
 * required|integer|min:1 — never nullable, never zero, never absent: N-12). The
 * EXISTENCE is this class: there is no constructor argument, no flag and no
 * setting that turns it off. StarvationCapPropertyTest is the physics proof.
 *
 * Clocks: the caller passes the ticket's DB-time `queued_at` and the DB-time
 * `now` (C-11, ADR 0010) — this class never reads a wall clock.
 */
final class StarvationCap
{
    public const SETTING = 'routing.starvation.minutes';

    public function __construct(private readonly SettingsRegistry $registry) {}

    /** The configured cap in minutes — always ≥ 1; the registry's validation is the floor. */
    public function minutes(int $configuredMinutes): int
    {
        // Validation physics: an invalid value cannot be READ as a cap. The registry
        // refuses null/0/negative at write; this refuses them at read as a second
        // layer, so a corrupted store cannot silently disable the guarantee.
        $this->registry->validate(self::SETTING, $configuredMinutes);

        return $configuredMinutes;
    }

    /** True when the ticket has waited past the cap — it goes to the HEAD, before any score. */
    public function isStarved(DateTimeImmutable $queuedAt, DateTimeImmutable $now, int $configuredMinutes): bool
    {
        $waitedSeconds = $now->getTimestamp() - $queuedAt->getTimestamp();

        return $waitedSeconds >= $this->minutes($configuredMinutes) * 60;
    }
}
