<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Inbound;

use App\Support\Cache\TenantCache;

/**
 * EC-01's batch-tail hint: which message ends a same-second burst.
 *
 * A customer sending 10 messages in a burst should trigger ONE AI turn decision,
 * on the tail — not ten. The mechanism is a 2-second debounce: every arrival
 * records itself as the current candidate; a confirm job dispatched 2 s behind
 * each arrival marks the hint ONLY if its message is still the candidate (nothing
 * newer arrived). Doc 09's turn-decision subscriber reads tailFor() — Doc 04 owns
 * setting the hint, never acting on it (pre-agreed Doc 09 bucket).
 *
 * Volatile Redis via TenantCache (C-07: prefixed, TTL'd, never source of truth) —
 * a lost hint costs one extra AI turn decision, nothing more.
 */
final class BatchTailHint
{
    /** Longer than any burst gap worth debouncing; the next burst overwrites. */
    private const CANDIDATE_TTL_SECONDS = 60;

    /** How long Doc 09's subscriber has to read the confirmed hint. */
    private const HINT_TTL_SECONDS = 300;

    public function __construct(private readonly TenantCache $cache) {}

    /** Every non-quarantined inbound records itself; the newest arrival wins. */
    public function recordArrival(string $conversationId, string $messageId): void
    {
        $this->cache->put(
            'batch-candidate:'.$conversationId,
            $messageId,
            self::CANDIDATE_TTL_SECONDS,
            'message.received (the next arrival overwrites the candidate)',
        );
    }

    /**
     * The 2-s-delayed confirm: still the candidate → this message IS the batch
     * tail; set the hint. Superseded → a newer message's confirm will handle it.
     */
    public function confirm(string $conversationId, string $messageId): bool
    {
        if ($this->cache->get('batch-candidate:'.$conversationId) !== $messageId) {
            return false;
        }

        $this->cache->put(
            'batch-tail:'.$conversationId,
            $messageId,
            self::HINT_TTL_SECONDS,
            'message.received (a new burst supersedes the hint)',
        );

        return true;
    }

    /** Doc 09's read side: the confirmed batch-tail message id, if any. */
    public function tailFor(string $conversationId): ?string
    {
        $tail = $this->cache->get('batch-tail:'.$conversationId);

        return is_string($tail) ? $tail : null;
    }
}
