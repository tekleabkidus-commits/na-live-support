<?php

declare(strict_types=1);

namespace App\Domain\Auth\Widget;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Str;

/**
 * Visitor sessions in Redis (Doc 02 FR-21, FR-22, EC-09; step-10 rulings).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE SHAPE. One key per live token:
 *
 *   t:{tenant}:widget-visitor:{sha256(token)}  →  JSON record, TTL 30 days
 *
 * Tenant-prefixed like every Redis key in the platform (C-07's convention,
 * applied here by hand exactly as SessionRegistry does): the widget key on
 * every request resolves the tenant BEFORE this store is consulted, so unlike
 * the resolver's keymap cache there is no pre-context exception to justify.
 * Keying by token hash means the plaintext token is never at rest anywhere.
 *
 * SLIDING 30-DAY TTL (FR-21): every authenticated touch re-arms the expiry.
 * The window is spec-fixed, not a setting — no document names a key for it,
 * and per CLAUDE.md inventing one would be scope creep.
 *
 * WHICH REDIS (step-10 ruling, decision 1): `volatile`, the same instance as
 * staff sessions. Named residual risk, recorded in BUILD-STATUS with a Doc 18
 * handoff: allkeys-lru may evict a visitor token before its 30 days are up
 * under memory pressure, silently downgrading US-07's "history still there a
 * week later" to EC-09's fresh-visitor path. Doc 18 owes an eviction policy
 * that spares TTL'd visitor keys (volatile-ttl or equivalent), sizing for the
 * visitor population, and an eviction metric. The durable instance is NOT the
 * answer — its contract is queues/outbox/timers only (CLAUDE.md §3).
 *
 * ROTATION (FR-22, decisions 2/3): the secondary cookie rotates on every
 * /session call; the previous secret stays valid for exactly one rotation of
 * grace. A token presented WITHOUT its cookie is never trusted as its visitor:
 * the caller gets a brand-new visitor identity, and this class offers no
 * operation — none — that re-points a token or a fresh visitor at an existing
 * visitor_id. Re-linking histories is a STAFF action (EC-09 / Doc 08), so the
 * capability is structurally absent here rather than guarded.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class VisitorTokenStore
{
    public const TTL_SECONDS = 30 * 24 * 3600;

    /**
     * Token prefix, for SEC-08's log scrubber and human triage. The entropy is
     * the 32 hex chars after it: 128 bits from the CSPRNG, per FR-21.
     */
    private const TOKEN_PREFIX = 'nvt_';

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly string $connection,
    ) {}

    /**
     * Mint a brand-new visitor session: new visitor_id, new token, new cookie.
     */
    public function issue(string $tenantId, string $widgetId, CarbonImmutable $at): IssuedVisitorSession
    {
        $token = self::TOKEN_PREFIX.bin2hex(random_bytes(16));
        $cookie = bin2hex(random_bytes(16));
        $visitorId = (string) Str::uuid7();

        $this->write($tenantId, $token, new VisitorRecord(
            visitorId: $visitorId,
            widgetId: $widgetId,
            cookieHash: hash('sha256', $cookie),
            previousCookieHash: null,
            issuedAt: $at,
            lastSeenAt: $at,
        ));

        return new IssuedVisitorSession($visitorId, $token, $cookie);
    }

    public function find(string $tenantId, string $token): ?VisitorRecord
    {
        $raw = $this->connection()->get($this->key($tenantId, $token));

        if (! is_string($raw)) {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return null;
        }

        $visitorId = $data['visitor_id'] ?? null;
        $widgetId = $data['widget_id'] ?? null;
        $cookieHash = $data['cookie_hash'] ?? null;
        $previousCookieHash = $data['previous_cookie_hash'] ?? null;
        $issuedAt = $data['issued_at'] ?? null;
        $lastSeenAt = $data['last_seen_at'] ?? null;

        // A malformed record reads as ABSENT, not as a crash: the visitor
        // silently re-establishes (EC-09's path), which is the only safe
        // degradation for corrupt auth state.
        if (! is_string($visitorId) || ! is_string($widgetId) || ! is_string($cookieHash)
            || ! ($previousCookieHash === null || is_string($previousCookieHash))
            || ! is_int($issuedAt) || ! is_int($lastSeenAt)) {
            return null;
        }

        return new VisitorRecord(
            visitorId: $visitorId,
            widgetId: $widgetId,
            cookieHash: $cookieHash,
            previousCookieHash: $previousCookieHash,
            issuedAt: CarbonImmutable::createFromTimestampUTC($issuedAt),
            lastSeenAt: CarbonImmutable::createFromTimestampUTC($lastSeenAt),
        );
    }

    /**
     * An authenticated touch: slide the 30-day window (FR-21 "sliding").
     */
    public function touch(string $tenantId, string $token, VisitorRecord $record, CarbonImmutable $at): void
    {
        $this->write($tenantId, $token, new VisitorRecord(
            visitorId: $record->visitorId,
            widgetId: $record->widgetId,
            cookieHash: $record->cookieHash,
            previousCookieHash: $record->previousCookieHash,
            issuedAt: $record->issuedAt,
            lastSeenAt: $at,
        ));
    }

    /**
     * A /session refresh from the record's rightful holder: rotate the cookie
     * (previous ← current, one grace generation) and slide the window. The
     * token and — critically — the visitor_id stay what they were.
     *
     * @return string the new cookie plaintext, for Set-Cookie
     */
    public function rotateCookie(string $tenantId, string $token, VisitorRecord $record, CarbonImmutable $at): string
    {
        $cookie = bin2hex(random_bytes(16));

        $this->write($tenantId, $token, new VisitorRecord(
            visitorId: $record->visitorId,
            widgetId: $record->widgetId,
            cookieHash: hash('sha256', $cookie),
            previousCookieHash: $record->cookieHash,
            issuedAt: $record->issuedAt,
            lastSeenAt: $at,
        ));

        return $cookie;
    }

    /**
     * Remaining lifetime of a live session, for tests proving the slide.
     */
    public function ttlSeconds(string $tenantId, string $token): ?int
    {
        $ttl = $this->connection()->ttl($this->key($tenantId, $token));

        return is_int($ttl) && $ttl > 0 ? $ttl : null;
    }

    private function write(string $tenantId, string $token, VisitorRecord $record): void
    {
        $payload = json_encode([
            'visitor_id' => $record->visitorId,
            'widget_id' => $record->widgetId,
            'cookie_hash' => $record->cookieHash,
            'previous_cookie_hash' => $record->previousCookieHash,
            'issued_at' => $record->issuedAt->getTimestamp(),
            'last_seen_at' => $record->lastSeenAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        $this->connection()->setex($this->key($tenantId, $token), self::TTL_SECONDS, $payload);
    }

    private function key(string $tenantId, string $token): string
    {
        return sprintf('t:%s:widget-visitor:%s', $tenantId, hash('sha256', $token));
    }

    private function connection(): Connection
    {
        return $this->redis->connection($this->connection);
    }
}
