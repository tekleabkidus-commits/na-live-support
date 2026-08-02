<?php

declare(strict_types=1);

namespace App\Domain\Auth\Disable;

use App\Domain\Auth\Tokens\TokenRevoker;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\Sessions\SessionRevoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Disables a user — sessions AND tokens, atomically (Doc 02 BR-04, EC-05).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT "ATOMICALLY" MEANS HERE (owner ruling, step 9 — also stated in the
 * BR-04 suite and the traceability entry):
 *
 *   1. DB ATOMICITY: `disabled_at` and every live token's `revoked_at` are one
 *      transaction — all-or-nothing, proven by an injected mid-transaction
 *      failure in the suite. A user who reads as disabled with live tokens, or
 *      revoked tokens on an enabled user, cannot exist even for a crash window.
 *   2. IMMEDIATE Redis session revocation after commit — key delete, pub/sub,
 *      socket close ≤ 2 s, through the same SessionRevoker as every other
 *      revocation (T-04).
 *   3. PER-REQUEST BACKSTOPS, each independently real: staff rehydration
 *      refuses a disabled user even if its session key survives
 *      (StaffUserProvider::retrieveById re-reads disabled state on every
 *      request; RefuseBlockedPrincipal covers login itself), and the bearer
 *      door refuses a disabled owner's token even if its `revoked_at` were
 *      somehow missed (resolve_api_token's user_disabled flag + the guard
 *      callback). The BR-04 suite sabotages each independently.
 *
 * Sessions live in Redis and cannot join a Postgres transaction; the backstops
 * are why that gap is safe: between commit and key-delete, a surviving session
 * or token authenticates NOTHING, because every per-request path re-reads
 * disabled state from the row that just changed.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * NO HTTP ROUTE CALLS THIS YET, deliberately: admin user management is
 * Doc 03's surface (admin.users.disable, high risk, with FR-08's last-admin
 * rail). Same precedent as S5's admin unlock — the action is built and proven
 * at this boundary; Doc 03 wires the route.
 */
final class UserDisabler
{
    public function __construct(
        private readonly TokenRevoker $tokens,
        private readonly SessionRevoker $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int how many tokens were revoked
     */
    public function disable(User $user, CarbonImmutable $now, ?string $actorId = null): int
    {
        $revokedTokens = 0;

        DB::transaction(function () use ($user, $now, $actorId, &$revokedTokens): void {
            $user->forceFill(['disabled_at' => $now])->save();

            $liveTokens = PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->identifier())
                ->whereNull('revoked_at')
                ->get();

            foreach ($liveTokens as $token) {
                // Same path as an individual revocation — per-token audit row,
                // per-token event, scope-cache forget — so BR-04 is a bulk
                // application of the ordinary rule, not a second rule.
                $this->tokens->revoke($token, 'user_disabled', $now, $actorId);
                $revokedTokens++;
            }

            $this->audit->record(
                actionKey: 'auth.user.disabled',
                entity: 'user',
                entityId: $user->identifier(),
                actorType: $actorId === null ? AuditActor::System : AuditActor::Staff,
                actorId: $actorId,
                payload: ['revoked_token_count' => $revokedTokens],
            );
        });

        // After commit, never inside it: a rolled-back disable must not have
        // cost anyone their sessions.
        $this->sessions->revokeAllFor($user->tenantId(), $user->identifier(), 'user_disabled', null, $actorId);

        return $revokedTokens;
    }
}
