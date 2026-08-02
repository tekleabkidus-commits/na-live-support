<?php

declare(strict_types=1);

namespace App\Domain\Auth\Tokens;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\PermissionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Mints API tokens — personal and machine (Doc 02 FR-18, FR-19).
 *
 * ONE minter for both kinds; the caller says which and who owns it. The
 * controllers own request-shape validation (expiry choice, the
 * auth.token.allow_no_expiry policy read); this class re-asserts the invariants
 * that must hold no matter who calls it:
 *
 *   - every ability is a REGISTERED permission key (FR-18 "selected from the
 *     RBAC permission catalog") — an unregistered string stored as an ability
 *     would be a scope nobody can reason about;
 *   - expiry is one of FR-18's fixed choices or absent — the three durations
 *     are specification, not configuration;
 *   - the plaintext is `nlt_` + 256 bits hex, shown once, stored as SHA-256.
 *
 * The audit row and the outbox event commit WITH the token row (C-06): a token
 * that exists but was never audited, or an audit for a token that rolled back,
 * are both lies the transaction boundary makes impossible.
 */
final class TokenMinter
{
    /** FR-18: the fixed expiry choices, in days. "None" is policy-gated by the caller. */
    public const EXPIRY_CHOICES_DAYS = [30, 90, 365];

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
    ) {}

    /**
     * @param  list<string>  $abilities
     * @param  int|null  $expiryDays  one of EXPIRY_CHOICES_DAYS, or null for no expiry
     * @param  string|null  $rotationOf  FR-19: the token id this one replaces, for the audit trail
     */
    public function mint(
        User $owner,
        string $name,
        array $abilities,
        ?int $expiryDays,
        CarbonImmutable $now,
        ?string $actorId = null,
        ?string $rotationOf = null,
    ): MintedToken {
        $this->assertAbilities($abilities);

        if ($expiryDays !== null && ! in_array($expiryDays, self::EXPIRY_CHOICES_DAYS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Token expiry must be one of [%s] days or null — FR-18 fixes the choices; they are not configurable.',
                implode(', ', self::EXPIRY_CHOICES_DAYS),
            ));
        }

        $plaintext = 'nlt_'.bin2hex(random_bytes(32));
        $kind = $owner->isServiceAccount() ? 'machine' : 'personal';

        $token = DB::transaction(function () use ($owner, $name, $abilities, $expiryDays, $now, $actorId, $rotationOf, $plaintext, $kind): PersonalAccessToken {
            $token = new PersonalAccessToken;
            $token->forceFill([
                'tokenable_type' => $owner->getMorphClass(),
                'tokenable_id' => $owner->identifier(),
                'name' => $name,
                'token' => hash('sha256', $plaintext),
                'abilities' => $abilities,
                'expires_at' => $expiryDays === null ? null : $now->addDays($expiryDays),
            ]);
            $token->save();

            $payload = [
                'kind' => $kind,
                'name' => $name,
                'abilities' => $abilities,
                'expires_at' => $token->expires_at?->toIso8601String(),
            ];

            if ($rotationOf !== null) {
                // FR-19's rotation trail: the replacement names its predecessor.
                $payload['rotation_of'] = $rotationOf;
            }

            $this->audit->record(
                actionKey: 'auth.token.created',
                entity: 'personal_access_token',
                entityId: $token->identifier(),
                actorType: AuditActor::Staff,
                actorId: $actorId ?? $owner->identifier(),
                payload: $payload,
            );

            // Identifier-only (step-9 ruling): consumers re-fetch by id under
            // their own authorisation; the bus never carries names or scopes.
            $this->events->publish('auth.token.created', [
                'tenant_id' => $owner->tenantId(),
                'user_id' => $owner->identifier(),
                'token_id' => $token->identifier(),
            ]);

            return $token;
        });

        return new MintedToken($token, $plaintext);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function assertAbilities(array $abilities): void
    {
        if ($abilities === []) {
            throw new InvalidArgumentException(
                'A token needs at least one ability — FR-18 scopes are selected from the catalog, and an '
                .'ability-less token could never pass any T-05 intersection.',
            );
        }

        foreach ($abilities as $ability) {
            $this->registry->assertRegistered($ability);
        }
    }
}
