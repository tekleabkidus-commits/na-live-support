<?php

declare(strict_types=1);

namespace App\Domain\Auth\Webhooks;

use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * FR-24's nonce half: each signed request is spendable exactly once inside
 * the ≤ 5 min window (Doc 02 FR-24; step-11 rulings 1 and 3).
 *
 * `SET NX EX` — one atomic operation answers "has this nonce been seen" and
 * "mark it seen" together, so two concurrent replays cannot both pass (a
 * read-then-write pair would let them). O(1), per §12's budget note.
 *
 * WHICH REDIS (ruling, decision 1): `volatile` — replay nonces are the
 * rate-limit/lockout-counter lifecycle family (CLAUDE.md §3), 5-minute TTL.
 * The named residual: LRU eviction inside the window would re-admit a replay
 * ONLY when the attacker also holds a valid signature whose timestamp is
 * still inside the ±5 min bound — the timestamp check is independent of this
 * class and caps the exposure (sabotage (d) proves that independence).
 *
 * OUTAGE POSTURE (ruling, decision 3 — a FILED INTERPRETATION, recorded in
 * ANSWERS-FROM-DOC-AUTHOR.md): unreachable cache → the exception propagates
 * and the middleware REFUSES. FS-01's "webhooks unaffected" states its own
 * rationale — no session dependency — and stays true where that rationale
 * applies (the secret_header surface touches no Redis at all). Accepting
 * unverifiable-for-replay traffic would unbound the replay window exactly
 * when the platform is degraded; real gateways retry, carrying availability.
 */
final class ReplayGuard
{
    public const WINDOW_SECONDS = 300;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly string $connection,
    ) {}

    /**
     * True if this nonce has never been seen in the window — and mark it
     * seen, atomically. False means replay.
     *
     * The nonce is stored HASHED: it is attacker-chosen input, and raw
     * attacker bytes do not belong in Redis keys (key-pattern injection,
     * unbounded length). C-07 prefix discipline as everywhere.
     */
    public function firstUse(string $tenantId, string $nonce): bool
    {
        $key = sprintf('t:%s:webhook-nonce:%s', $tenantId, hash('sha256', $nonce));

        // phpredis takes SET options as an array, NOT protocol-style
        // variadics — the variadic form type-errors, and the first version
        // of the middleware's catch was broad enough to dress that TypeError
        // up as a cache outage: every valid DLR refused as
        // replay_cache_unavailable across the whole suite. The catch is
        // now connection-scoped so a coding bug here is a loud 500 again.
        $result = $this->redis->connection($this->connection)
            ->command('set', [$key, '1', ['nx', 'ex' => self::WINDOW_SECONDS]]);

        return $result !== false && $result !== null;
    }
}
