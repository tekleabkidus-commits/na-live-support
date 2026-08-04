<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-user permission exception (Doc 03 FR-04): allow or deny one catalog
 * key, optionally expiring, always with a granter and a ≥10-char reason
 * (SEC-04).
 *
 * The `scope` column is §19's RESERVED expansion — nothing reads it in v1;
 * an override grants/denies the VERB inside the role's scope (FR-04's
 * documented limitation, pinned by test).
 *
 * Expiry truth is the RESOLVER's DB-time comparison (FR-09/EC-09) — this
 * model deliberately has no isExpired() against the app clock. Removal is
 * soft (`revoked_at` + cause); the live() scope is the only set the resolver
 * ever loads.
 *
 * @property string $user_id
 * @property string $permission
 * @property string $effect
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string $granted_by
 * @property string $reason
 * @property string|null $scope
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property string|null $revoke_cause
 */
#[Fillable([
    'user_id',
    'permission',
    'effect',
    'expires_at',
    'granted_by',
    'reason',
])]
final class UserPermissionOverride extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'user_permission_overrides';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * The tenant id as the string it always is (C-02 NOT NULL) — same
     * validating-accessor shape as User::identifier().
     */
    public function tenantId(): string
    {
        $tenantId = $this->getAttribute('tenant_id');

        if (! is_string($tenantId) || $tenantId === '') {
            throw new \LogicException('Override has no tenant_id — the column is NOT NULL (Doc 01 C-02).');
        }

        return $tenantId;
    }

    /**
     * Not-revoked rows. Expired-but-untidied rows ARE included: the resolver
     * applies the expiry check against DB time itself, so tidiness is never a
     * security dependency (FR-09 — cleanup only tidies).
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
