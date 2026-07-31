<?php

declare(strict_types=1);

namespace App\Domain\Auth\Login;

use App\Domain\Foundation\Tenancy\TenantState;
use Carbon\CarbonImmutable;

/**
 * One candidate account for an email presented at login (Doc 02 FR-01; ruling Q1).
 *
 * A value object over one row from `resolve_staff_login()`. It exists because the
 * login path must reason about accounts BEFORE binding a tenant, and doing that
 * with Eloquent models would be a category error: a model implies a bound tenant
 * and a global scope, and neither is available yet. This carries only what the
 * decision needs, and it carries no secret beyond the password digest the
 * verifier consumes.
 *
 * "Candidate" is literal. Emails are unique per tenant, so an address may match
 * several accounts across tenants; which one — if any — is the real account is
 * decided by verifying the password, not by anything the address alone can say.
 */
final readonly class StaffLoginCandidate
{
    public function __construct(
        public string $userId,
        public string $tenantId,
        public string $passwordHash,
        public bool $disabled,
        public ?CarbonImmutable $lockedUntil,
        public int $failedLoginAttempts,
        public ?CarbonImmutable $firstFailedLoginAt,
        public bool $twoFactorEnabled,
        public bool $passwordSet,
        public ?string $locale,
        public TenantState $tenantState,
    ) {}

    /**
     * Build from one `resolve_staff_login()` row.
     *
     * The row is a plain stdClass from the driver, so every field is `mixed`
     * until proven otherwise. Each is validated rather than cast: a shape change
     * in the SQL function must fail here, loudly and at the boundary, rather
     * than silently producing a candidate with an empty password hash — which
     * would be a candidate that no password can match, i.e. a login outage that
     * looks like everyone forgetting their password at once.
     */
    public static function fromRow(object $row): self
    {
        /** @var array<string, mixed> $fields */
        $fields = get_object_vars($row);

        return new self(
            userId: self::requireString($fields, 'user_id'),
            tenantId: self::requireString($fields, 'tenant_id'),
            passwordHash: self::requireString($fields, 'password_hash'),
            disabled: (bool) ($fields['disabled'] ?? false),
            lockedUntil: self::optionalTime($fields, 'locked_until'),
            failedLoginAttempts: self::requireInt($fields, 'failed_login_attempts'),
            firstFailedLoginAt: self::optionalTime($fields, 'first_failed_login_at'),
            twoFactorEnabled: (bool) ($fields['two_factor_enabled'] ?? false),
            passwordSet: (bool) ($fields['password_set'] ?? false),
            locale: self::optionalString($fields, 'locale'),
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
                'resolve_staff_login() returned no usable "%s". The function\'s shape and this value '
                .'object have diverged — see the create_resolve_staff_login_function migration.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function requireInt(array $fields, string $key): int
    {
        $value = $fields[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function optionalString(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function optionalTime(array $fields, string $key): ?CarbonImmutable
    {
        $value = $fields[$key] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    /**
     * FR-03: locked accounts refuse the attempt regardless of the password.
     *
     * The clock is injected by the caller rather than read here — CLAUDE.md §8
     * forbids tests asserting on now(), and a lockout that reads a wall clock
     * inside a value object cannot be tested at a boundary at all.
     */
    public function isLockedAt(CarbonImmutable $now): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil->greaterThan($now);
    }
}
