<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A staff user, belonging to exactly one tenant (Doc 01 FR-02, FR-03a; Doc 02).
 *
 * Doc 01 owned this model's tenancy: it is tenant-scoped like any other, so the
 * staff-session resolution path (FR-03a — "token belongs to a user; user belongs
 * to a tenant") has an edge to follow, and no query for users can cross a tenant
 * boundary.
 *
 * THE PRE-TENANT CONSTRAINT DOC 01 HANDED OVER, AND HOW DOC 02 ANSWERED IT.
 * Looking a user up BY EMAIL in order to authenticate them happens BEFORE any
 * tenant is resolved, so it cannot go through this model's global scope — and
 * Doc 01 forbade solving that by removing the scope. It is solved the
 * resolve_tenant_key way instead: `resolve_staff_login(email)`, a SECURITY
 * DEFINER function owned by the NOLOGIN key_resolver role, returning only the
 * candidate credential rows for one exact address. See
 * StaffLoginResolver and the function's migration. The scope below is intact and
 * every ordinary query still goes through it.
 *
 * WHY THERE IS NO `two_factor_recovery_codes` COLUMN even though Fortify expects
 * one: SEC-03 requires recovery codes hashed and displayed exactly once, and
 * Fortify's storage is encrypted-retrievable with a re-display route. The hashed
 * `user_recovery_codes` table replaces it (owner ruling Q6).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable implements TenantScoped
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',

            // Doc 02 auth state. Timestamps rather than booleans throughout —
            // BR-04 and AC-02 both need to record WHEN, and a boolean throws
            // that away (see the migration's docblock).
            'password_set_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'disabled_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'first_failed_login_at' => 'datetime',
            'locked_until' => 'datetime',

            // The TOTP secret is the one credential in this document that cannot
            // be a one-way hash — verification needs the original. Encrypted at
            // rest, never readable through any surface (BR-02), and excluded from
            // serialization by the Hidden attribute above.
            'two_factor_secret' => 'encrypted',
        ];
    }

    /**
     * The primary key as the string it always is.
     *
     * `getKey()` is `mixed` on every Eloquent model, which is honest — a model's
     * key type is not knowable statically. Here it IS knowable: C-01 makes every
     * primary key a UUIDv7 string, and the column is `uuid NOT NULL`. This
     * validates rather than casts, so a genuinely wrong type fails loudly at the
     * boundary instead of silently stringifying.
     */
    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new \LogicException('User has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }

    public function tenantId(): string
    {
        $tenantId = $this->getAttribute('tenant_id');

        if (! is_string($tenantId) || $tenantId === '') {
            throw new \LogicException('User has no tenant_id — the column is NOT NULL (Doc 01 C-02).');
        }

        return $tenantId;
    }

    /**
     * FR-05 / BR-04: a disabled account authenticates nowhere.
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * FR-06/FR-07: 2FA gates login only once ENROLMENT IS CONFIRMED. A started
     * but unconfirmed enrolment must not challenge, or a user who abandoned the
     * wizard could never log in again.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }
}
