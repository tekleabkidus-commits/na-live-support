<?php

declare(strict_types=1);

namespace App\Domain\Auth\Tokens;

use App\Models\PersonalAccessToken;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\Tokens\TokenScopeGate;
use App\Support\Events\EventPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Revokes one token (Doc 02 FR-18 "individually revocable", FR-19's rotation
 * close-out, BR-04's per-token entries).
 *
 * Revocation is a timestamp, never a delete (the app role holds no DELETE —
 * database physics, not convention), so the trail keeps the token that WAS
 * used. The audit row and outbox event commit with the timestamp; the T-05
 * scope cache is dropped after commit so a revoked-then-reissued id cannot
 * serve a stale allow.
 */
final class TokenRevoker
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
        private readonly TokenScopeGate $gate,
    ) {}

    public function revoke(
        PersonalAccessToken $token,
        string $reason,
        CarbonImmutable $now,
        ?string $actorId = null,
        ?string $tenantId = null,
    ): void {
        DB::transaction(function () use ($token, $reason, $now, $actorId, $tenantId): void {
            $token->forceFill(['revoked_at' => $now])->save();

            $ownerId = $token->tokenable_id;

            $this->audit->record(
                actionKey: 'auth.token.revoked',
                entity: 'personal_access_token',
                entityId: $token->identifier(),
                actorType: AuditActor::Staff,
                actorId: $actorId ?? $ownerId,
                payload: ['reason' => $reason],
                tenantId: $tenantId,
            );

            $this->events->publish('auth.token.revoked', [
                'tenant_id' => $token->tenant_id,
                'user_id' => $ownerId,
                'token_id' => $token->identifier(),
            ]);
        });

        $this->gate->forget($token);
    }
}
