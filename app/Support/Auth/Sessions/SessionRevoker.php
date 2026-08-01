<?php

declare(strict_types=1);

namespace App\Support\Auth\Sessions;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\RunsWithinTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Revoking a session, all the way down to the socket (Doc 02 T-04, FR-12, AC-04).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * T-04 SPELLS OUT THREE EFFECTS, AND ALL THREE MATTER SEPARATELY:
 *
 *   1. the session key is DELETED — the next HTTP request is unauthenticated;
 *   2. `session.revoked:{id}` is PUBLISHED — other nodes learn about it;
 *   3. that session's SOCKETS ARE CLOSED — within 2 s (AC-04).
 *
 * Only (1) is enough for HTTP. Without (3) a revoked agent keeps receiving live
 * conversation traffic over an already-open websocket for as long as they leave
 * the tab open: the door is locked while they are still inside. That is the
 * scenario US-03 describes — "I spot an unfamiliar login location and revoke that
 * session instantly" — and "instantly" is worth nothing if the attacker's socket
 * survives it.
 *
 * HOW THE SOCKET IS CLOSED. Reverb implements Pusher's
 * `/apps/{app}/users/{user}/terminate_connections`, so revocation calls it over
 * the private API rather than inventing a signalling channel. The call is made
 * for the USER, not the session: Reverb authenticates connections per user, and
 * a terminated user simply reconnects on their surviving sessions. Over-closing
 * a socket costs a reconnect; under-closing one leaves a revoked principal
 * streaming, so the asymmetry is deliberate.
 *
 * FAILURE POSTURE. If Reverb is unreachable the session is STILL revoked — the
 * HTTP half already happened, and refusing to revoke because a socket could not
 * be closed would be strictly worse. The failure is logged with a named metric
 * (NFR-04's fail-open-with-metric shape); it is not swallowed.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SessionRevoker
{
    use RunsWithinTenant;

    public function __construct(
        private readonly SessionRegistry $registry,
        private readonly RedisFactory $redis,
        private readonly AuditLogger $audit,
        private readonly string $connection,
    ) {}

    /**
     * Revoke one session and close its owner's sockets.
     */
    public function revoke(
        string $tenantId,
        string $userId,
        string $sessionId,
        string $reason,
        ?string $actorId = null,
    ): void {
        // Storage first: after this line the session cannot authenticate an HTTP
        // request, whatever else fails below.
        $this->registry->forget($tenantId, $userId, $sessionId);

        $this->publish($tenantId, $sessionId);
        $this->closeSockets($userId);

        $this->withinTenant($tenantId, function () use ($tenantId, $userId, $sessionId, $reason, $actorId): void {
            $this->audit->record(
                actionKey: 'auth.session.revoked',
                entity: 'user',
                entityId: $userId,
                actorType: $actorId === null ? AuditActor::System : AuditActor::Staff,
                payload: [
                    'reason' => $reason,
                    // The session id is an opaque identifier, not a credential —
                    // the session it named no longer exists by the time this is
                    // written. It is what makes a revocation traceable to the row
                    // on the devices screen the operator clicked.
                    'session_id' => $sessionId,
                ],
                actorId: $actorId,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Revoke every session of a user except, optionally, the current one.
     *
     * @return list<string> the revoked session ids
     */
    public function revokeAllFor(
        string $tenantId,
        string $userId,
        string $reason,
        ?string $exceptSessionId = null,
        ?string $actorId = null,
    ): array {
        $revoked = [];

        foreach ($this->registry->liveSessionsFor($tenantId, $userId) as $sessionId) {
            if ($sessionId === $exceptSessionId) {
                continue;
            }

            $this->revoke($tenantId, $userId, $sessionId, $reason, $actorId);
            $revoked[] = $sessionId;
        }

        return $revoked;
    }

    /**
     * Doc 01 EC-03 (the inherited task): a suspended tenant's live sessions die
     * now, not at their owners' next request.
     *
     * @return int how many sessions were revoked
     */
    public function revokeEntireTenant(string $tenantId, string $reason): int
    {
        $count = 0;

        foreach ($this->registry->allForTenant($tenantId) as $entry) {
            $this->revoke($tenantId, $entry['user_id'], $entry['session_id'], $reason);
            $count++;
        }

        return $count;
    }

    /**
     * T-04's publish. Other application nodes subscribe to learn that a session
     * died elsewhere; the payload is the session id and nothing else.
     */
    private function publish(string $tenantId, string $sessionId): void
    {
        $this->redis->connection($this->connection)->publish(
            sprintf('session.revoked:%s', $sessionId),
            (string) json_encode(['tenant_id' => $tenantId, 'revoked_at' => CarbonImmutable::now()->toIso8601String()]),
        );
    }

    private function closeSockets(string $userId): void
    {
        $host = config('reverb.apps.apps.0.options.host');
        $port = config('reverb.apps.apps.0.options.port');
        $appId = config('reverb.apps.apps.0.app_id');

        if (! is_string($host) || ! is_string($appId)) {
            return;
        }

        try {
            Http::withoutVerifying()
                ->timeout(2)
                ->post(sprintf('https://%s:%s/apps/%s/users/%s/terminate_connections',
                    $host,
                    is_scalar($port) ? (string) $port : '443',
                    $appId,
                    $userId,
                ));
        } catch (Throwable $e) {
            // FAIL-OPEN WITH A METRIC (NFR-04). The session is already revoked;
            // refusing to complete because a socket could not be closed would
            // leave the account MORE accessible, not less.
            logger()->warning('Session revoked but socket termination failed', [
                'metric' => 'auth.session.socket_close_failed',
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
