<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use App\Domain\Foundation\Tenancy\TenantState;

/**
 * One `resolve_auth_action_token()` row, validated at the boundary
 * (Doc 02 FR-16/FR-17; same shape and same reasoning as StaffLoginCandidate).
 *
 * A value object rather than a model, because the presenter of a token has no
 * tenant bound yet — an Eloquent read here would be a category error. It
 * carries the minimum the redemption decision needs and no secret at all: the
 * door never returns the hash, and the hash is not the token anyway.
 */
final readonly class ActionTokenRedemption
{
    public function __construct(
        public string $tokenId,
        public string $tenantId,
        public string $userId,
        public TokenPurpose $purpose,
        public string $sentToEmail,
        public bool $expired,
        public bool $consumed,
        public TenantState $tenantState,
    ) {}

    public static function fromRow(object $row): self
    {
        /** @var array<string, mixed> $fields */
        $fields = get_object_vars($row);

        return new self(
            tokenId: self::requireString($fields, 'token_id'),
            tenantId: self::requireString($fields, 'tenant_id'),
            userId: self::requireString($fields, 'user_id'),
            purpose: TokenPurpose::from(self::requireString($fields, 'purpose')),
            sentToEmail: self::requireString($fields, 'sent_to_email'),
            expired: (bool) ($fields['expired'] ?? true),
            consumed: (bool) ($fields['consumed'] ?? true),
            tenantState: TenantState::from(self::requireString($fields, 'tenant_state')),
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function requireString(array $fields, string $key): string
    {
        $value = $fields[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new \LogicException(sprintf(
                'resolve_auth_action_token() returned no usable "%s". The function\'s shape and this '
                .'value object have diverged — see the create_auth_action_tokens_table migration.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * Every gate a token must pass before it may act, in one place.
     *
     * The DEFAULTS in fromRow() fail closed: a row missing its `expired` or
     * `consumed` flag is treated as dead, not alive — absence of proof is not
     * proof, the same posture Doc 01 takes everywhere.
     */
    public function isLive(TokenPurpose $expected): bool
    {
        // The expiry flag is the DOOR's comparison, at DB time (C-11) — no app
        // clock re-checks it here, deliberately: two clocks disagreeing at the
        // boundary would make redemption outcomes depend on which one you ask.
        return $this->purpose === $expected
            && ! $this->expired
            && ! $this->consumed
            && $this->tenantState === TenantState::Active;
    }
}
