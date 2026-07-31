<?php

declare(strict_types=1);

namespace App\Support\Auth\Sessions;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use SessionHandlerInterface;

/**
 * Session storage under a per-tenant key prefix (Doc 02 T-04, FR-10; C-07).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THE PREFIX BUYS, given that RLS already isolates the database.
 *
 * Sessions do not live in Postgres, so no RLS policy covers them — Redis is a
 * flat keyspace where any key is readable by anything holding the connection.
 * The prefix is what makes tenancy legible THERE:
 *
 *   - Revocation can be expressed per tenant: suspending an operator (Doc 01
 *     EC-03, the inherited task) means deleting `t:{tenant}:sess:*`, which is a
 *     bounded, auditable operation rather than a scan of every session in the
 *     platform deciding one by one.
 *   - C-07's convention ("every cache key is prefixed t:{tenant}:") stops being
 *     a rule that sessions quietly exempt themselves from.
 *   - An operator's session data is separable at the storage layer, which is
 *     what an export or erasure request (Doc 17) eventually needs.
 *
 * GUEST SESSIONS ARE UNPREFIXED, and that is deliberate rather than an omission:
 * before login there is no tenant, and inventing one would either leak (guess a
 * tenant) or create a fake namespace. A guest session carries no privileges, so
 * it has nothing to isolate. At login the session id is regenerated (SEC-04)
 * and the new id is written under the tenant prefix — so the unprefixed guest
 * session is not migrated, it is abandoned.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TenantPrefixedSessionHandler implements SessionHandlerInterface
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantHint $hint,
        private readonly string $connection,
        private readonly int $minutes,
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $value = $this->connection()->get($this->key($id));

        return is_string($value) ? $value : '';
    }

    public function write(string $id, string $data): bool
    {
        // Every entry carries a TTL (C-07: "every cache entry has a mandatory
        // TTL"). A session without one is a memory leak that outlives the
        // absolute-lifetime rule FR-11 sets.
        $this->connection()->setex($this->key($id), $this->minutes * 60, $data);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->connection()->del($this->key($id));

        return true;
    }

    public function gc(int $max_lifetime): int
    {
        // Redis expires keys itself; there is nothing to sweep. Returning 0
        // rather than false because false means failure, and this succeeded.
        return 0;
    }

    /**
     * The storage key for a session id under the current request's hint.
     *
     * PUBLIC because revocation needs to build the same key from outside a
     * request (the sessions registry deletes keys for sessions it is evicting),
     * and duplicating the format there is how the two drift apart.
     */
    public function key(string $id): string
    {
        $tenantId = $this->hint->current();

        return $tenantId === null
            ? sprintf('guest:sess:%s', $id)
            : self::keyFor($tenantId, $id);
    }

    public static function keyFor(string $tenantId, string $sessionId): string
    {
        return sprintf('t:%s:sess:%s', $tenantId, $sessionId);
    }

    private function connection(): \Illuminate\Redis\Connections\Connection
    {
        return $this->redis->connection($this->connection);
    }
}
