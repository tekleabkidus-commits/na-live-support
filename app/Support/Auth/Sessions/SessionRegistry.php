<?php

declare(strict_types=1);

namespace App\Support\Auth\Sessions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

/**
 * The per-user index of live sessions (Doc 02 FR-10, FR-12, T-04, AC-04).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY AN INDEX EXISTS AT ALL, given that sessions are already in Redis.
 *
 * Session storage is keyed by session ID, which answers "is this session valid".
 * Three requirements ask the opposite question — "which sessions does this user
 * have" — and none of them can be answered by a keyspace scan on a live system:
 *
 *   FR-10  cap concurrent sessions at N, evicting the OLDEST
 *   FR-12  the devices screen lists a user's active sessions
 *   T-04   revocation, including "revoke all others"
 *
 * So each user gets a sorted set: members are session ids, scores are last
 * activity as a unix timestamp. Sorted-by-score is exactly what "evict the
 * oldest" needs, and it is O(log n) rather than a SCAN over every session in the
 * platform.
 *
 * THE INDEX IS BOOKKEEPING, NOT TRUTH. Session storage remains authoritative: a
 * session exists if its key exists. The index can only ever be stale in the safe
 * direction — it may list a session that has since expired, which the reconcile
 * on read discards. It must never be trusted to say a session is GONE when its
 * key still lives, which is why revocation deletes the storage key FIRST and the
 * index entry second. The tests assert on session KEYS, never on this index, for
 * that reason.
 *
 * TENANT-PREFIXED like everything else (C-07): `t:{tenant}:user-sessions:{user}`.
 * Doc 01 EC-03's suspension sweep needs to reach every session of one tenant, and
 * a flat keyspace would make that a scan.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SessionRegistry
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly string $connection,
        private readonly int $ttlMinutes,
        private readonly SessionMetadataStore $metadata,
    ) {}

    /**
     * Record a session as live, or refresh its last-activity score.
     */
    public function register(string $tenantId, string $userId, string $sessionId, CarbonImmutable $at): void
    {
        $key = $this->key($tenantId, $userId);

        $this->connection()->zadd($key, $at->getTimestamp(), $sessionId);

        // The index must not outlive the sessions it describes. Refreshed on
        // every touch, so an active user's index never expires under them.
        $this->connection()->expire($key, $this->ttlMinutes * 60);
    }

    /**
     * Session ids for a user, oldest activity first.
     *
     * Reconciled against storage on the way out: an id whose session key is gone
     * (expired, revoked elsewhere) is dropped from the index rather than
     * returned. This is the only place the index self-heals, and it is why a
     * stale entry can never become a phantom "active session" on the devices
     * screen.
     *
     * `$assumeLive` EXISTS BECAUSE OF A REAL BUG, not for convenience. Laravel
     * writes the session to storage at the END of the request, so during login
     * the session that has just been registered HAS NO KEY YET. Reconciliation
     * treated that as "expired" and removed it — and since it was the only
     * member, Redis dropped the whole index. The symptom was silent: logins
     * succeeded, the cap never fired, and the index appeared never to have been
     * written at all. The caller that is mid-establishment names its own session
     * here so it is never judged by a key it has not had the chance to write.
     *
     * @return list<string>
     */
    public function liveSessionsFor(string $tenantId, string $userId, ?string $assumeLive = null): array
    {
        $key = $this->key($tenantId, $userId);

        /** @var list<string> $ids */
        $ids = $this->connection()->zrange($key, 0, -1);

        $live = [];
        foreach ($ids as $id) {
            if ($id === $assumeLive) {
                $live[] = $id;

                continue;
            }

            if ((bool) $this->connection()->exists(TenantPrefixedSessionHandler::keyFor($tenantId, $id))) {
                $live[] = $id;

                continue;
            }

            $this->connection()->zrem($key, $id);
        }

        return $live;
    }

    /**
     * FR-10: enforce the concurrent-session cap, evicting the OLDEST first.
     *
     * Returns the session ids that were evicted, so the caller can audit them.
     *
     * @return list<string>
     */
    public function enforceCap(string $tenantId, string $userId, int $max, string $keepSessionId): array
    {
        $live = $this->liveSessionsFor($tenantId, $userId, assumeLive: $keepSessionId);

        if (count($live) <= $max) {
            return [];
        }

        // Oldest-first ordering comes from the sorted set. The session that just
        // authenticated is protected explicitly rather than by assuming it sorts
        // last — a clock with one-second resolution can tie, and evicting the
        // session you just created would log a user out at the moment they
        // logged in.
        $evictable = array_values(array_filter($live, static fn (string $id): bool => $id !== $keepSessionId));
        $evictCount = count($live) - $max;
        $evicted = array_slice($evictable, 0, $evictCount);

        foreach ($evicted as $id) {
            $this->forget($tenantId, $userId, $id);
        }

        return $evicted;
    }

    /**
     * Destroy one session: storage first, index second.
     *
     * ORDER IS THE CONTRACT. If the index entry went first and the process died,
     * the session key would survive with nothing pointing at it — a live session
     * invisible to the devices screen and to "revoke all others", which is the
     * worst possible residue. The reverse (key gone, index entry lingering) is
     * self-healing on the next read.
     */
    public function forget(string $tenantId, string $userId, string $sessionId): void
    {
        $this->connection()->del(TenantPrefixedSessionHandler::keyFor($tenantId, $sessionId));
        $this->connection()->zrem($this->key($tenantId, $userId), $sessionId);

        // FR-12's metadata dies with the session, last — a lingering meta hash
        // with no session key is invisible (every reader goes through the live
        // list), and its TTL bounds the residue anyway.
        $this->metadata->forget($tenantId, $sessionId);
    }

    /**
     * Live sessions with their last-activity timestamps, oldest first — the
     * devices screen's "last active" column comes from the index's own scores
     * (EnforceSessionLifetime refreshes them per request).
     *
     * @return array<string, int> session id => unix timestamp
     */
    public function liveSessionsWithActivity(string $tenantId, string $userId, ?string $assumeLive = null): array
    {
        $out = [];
        foreach ($this->liveSessionsFor($tenantId, $userId, $assumeLive) as $sessionId) {
            $score = $this->connection()->zscore($this->key($tenantId, $userId), $sessionId);
            $out[$sessionId] = is_numeric($score) ? (int) $score : 0;
        }

        return $out;
    }

    /**
     * Every live session for a tenant, as {userId, sessionId} pairs.
     *
     * Used by Doc 01 EC-03's suspension sweep. A SCAN is acceptable here and
     * nowhere else: suspension is a rare platform action, and the alternative —
     * a second index of users-per-tenant — would be another structure to keep
     * consistent for one caller.
     *
     * @return list<array{user_id: string, session_id: string}>
     */
    public function allForTenant(string $tenantId): array
    {
        $pattern = sprintf('t:%s:user-sessions:*', $tenantId);
        $configuredPrefix = config('database.redis.options.prefix');
        $prefix = is_string($configuredPrefix) ? $configuredPrefix : '';

        $out = [];
        foreach ($this->scan($pattern) as $indexKey) {
            // Redis returns fully-prefixed keys from SCAN, but the client adds
            // the prefix again on every command — strip it or every read misses.
            $logical = str_starts_with($indexKey, $prefix) ? substr($indexKey, strlen($prefix)) : $indexKey;
            $userId = (string) mb_strrchr($logical, ':', false);
            $userId = ltrim($userId, ':');

            foreach ($this->liveSessionsFor($tenantId, $userId) as $sessionId) {
                $out[] = ['user_id' => $userId, 'session_id' => $sessionId];
            }
        }

        return $out;
    }

    /**
     * KEYS rather than SCAN, deliberately and narrowly.
     *
     * SCAN is the right instinct on a live keyspace and the wrong tool here: its
     * PhpRedis signature takes the cursor BY REFERENCE with the pattern as a
     * separate argument, which does not survive Laravel's connection proxy
     * cleanly, and the iteration adds real complexity for a caller that runs
     * once per tenant SUSPENSION — a rare platform action, not a request path.
     * The pattern is also tightly bounded (one tenant's index keys), so the
     * blocking window is proportional to one operator's user count rather than
     * to the whole keyspace.
     *
     * If this ever moves onto a hot path, it must become SCAN.
     *
     * @return list<string>
     */
    private function scan(string $pattern): array
    {
        /** @var list<string> $keys */
        $keys = $this->connection()->keys($pattern);

        return array_values(array_unique($keys));
    }

    private function key(string $tenantId, string $userId): string
    {
        return sprintf('t:%s:user-sessions:%s', $tenantId, $userId);
    }

    private function connection(): Connection
    {
        return $this->redis->connection($this->connection);
    }
}
