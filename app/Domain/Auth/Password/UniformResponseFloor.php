<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

/**
 * The reset/invite request paths' timing equalizer (Doc 02 AC-01, SEC-01;
 * ruling R-3's sibling).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A FLOOR, NOT PADDING-BY-WORK. The login path equalizes with dummy argon2id
 * verifications because its real work IS argon2id. The request paths' real
 * work is different in kind — token mint, two DB writes, a queue push, per
 * candidate — and zero of it happens for an unknown address. Faking that work
 * convincingly would mean fake writes; instead the whole handler runs, the
 * elapsed time is measured, and the response waits out the remainder of a
 * fixed floor. 0, 1 and N candidates all answer at the floor.
 *
 * THE FLOOR IS ALSO A TESTED MINIMUM, which is what keeps it alive: the
 * enumeration suite asserts every path takes AT LEAST the floor, so a future
 * "optimisation" that deletes the sleep fails the suite even though the raw
 * per-candidate delta might still sit inside AC-01's ±50 ms today. That is the
 * S4 lesson (a floor assertion catches the both-paths-got-cheap regression)
 * applied at the design stage instead of after the incident.
 *
 * 150 ms swamps the real work (single-digit ms per candidate) by more than an
 * order of magnitude and costs nothing that matters: these are rare, human-
 * initiated paths, and NFR-01's "excluding intentional equalization" clause is
 * this cost's sanction. EXCEEDING the floor (N large enough that real work
 * passes 150 ms) would degrade equalization — the same stated bound R-3
 * accepts at EQUALISED_VERIFICATIONS, and unreachable here below ~30
 * candidate tenants for one address.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class UniformResponseFloor
{
    public const FLOOR_MS = 150.0;

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function run(callable $work)
    {
        $started = hrtime(true);

        try {
            return $work();
        } finally {
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;

            if ($elapsedMs < self::FLOOR_MS) {
                usleep((int) round((self::FLOOR_MS - $elapsedMs) * 1000));
            }
        }
    }
}
