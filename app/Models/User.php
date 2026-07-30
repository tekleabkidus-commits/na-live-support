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
 * A staff user, belonging to exactly one tenant (Doc 01 FR-02, FR-03a).
 *
 * SEAM FOR DOC 02. Authentication mechanics — password policy, TOTP enrolment,
 * lockout, session invalidation, enumeration defence — are Doc 02's and are
 * deliberately absent here. What Doc 01 owns, and what this adds, is tenancy: the
 * model is tenant-scoped like any other, so the staff-session resolution path
 * (FR-03a — "token belongs to a user; user belongs to a tenant") has an edge to
 * follow, and no query for users can cross a tenant boundary.
 *
 * A CONSTRAINT THIS HANDS TO DOC 02, stated so it is not discovered late: looking
 * a user up BY EMAIL in order to authenticate them happens BEFORE any tenant is
 * resolved, so it cannot go through this model's global scope. That is the same
 * chicken-and-egg as tenant_keys, and it has the same shape of answer — a
 * narrowly-scoped lookup that does not hand the caller general read access.
 * Doc 02 should not solve it by removing the scope.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
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
        ];
    }
}
