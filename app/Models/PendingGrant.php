<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A four-eyes pending high-risk grant (Doc 03 T-06/FR-08b). The resolver
 * ignores this table entirely — a pending row grants NOTHING; only a distinct
 * second admin's approval applies the underlying grant (ruling R-9 defines
 * who may approve). Auto-expires 24 h after creation, by DB time. Rows
 * transition status and are never deleted (FS-04: a stuck approval stays
 * visible and inert).
 *
 * @property string $subject_type
 * @property string|null $role_id
 * @property string|null $user_id
 * @property string $permission
 * @property string $requested_by
 * @property string $reason
 * @property string $status
 * @property string|null $decided_by
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon $expires_at
 */
#[Fillable([
    'subject_type',
    'role_id',
    'user_id',
    'permission',
    'requested_by',
    'reason',
    'expires_at',
])]
final class PendingGrant extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'pending_grants';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /** @return BelongsTo<User, $this> */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
