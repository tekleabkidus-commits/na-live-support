<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A role (Doc 03 FR-02/T-01): name unique per live tenant set, a permission
 * string[] from the closed catalog, a per-module scope map, `is_system` for
 * seeded templates.
 *
 * Soft-deleted by ruling R-11 (ADR 0003 revokes DELETE); `version` is EC-03's
 * optimistic lock — every editor sends the version it read, the update is
 * `WHERE version = ?`, and a miss is the concurrent-edit conflict, never a
 * silent last-write-wins. `seeded_fingerprint` carries EC-07 (see the
 * migration docblock). `ip_allowlist` is Doc 02 SEC-05's per-role list.
 *
 * @property string $name
 * @property string|null $description
 * @property string $color
 * @property list<string> $permissions
 * @property array<string, string> $scopes
 * @property bool $is_system
 * @property string|null $seeded_fingerprint
 * @property list<string>|null $ip_allowlist
 * @property int $version
 */
#[Fillable([
    'name',
    'description',
    'color',
    'permissions',
    'scopes',
    'is_system',
    'seeded_fingerprint',
    'ip_allowlist',
])]
final class Role extends Model implements TenantScoped
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'roles';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'scopes' => 'array',
            'is_system' => 'bool',
            'ip_allowlist' => 'array',
            'version' => 'int',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The primary key as the string it always is (C-01) — validating
     * accessor, same shape as User::identifier().
     */
    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new \LogicException('Role has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }

    public function tenantId(): string
    {
        $tenantId = $this->getAttribute('tenant_id');

        if (! is_string($tenantId) || $tenantId === '') {
            throw new \LogicException('Role has no tenant_id — the column is NOT NULL (Doc 01 C-02).');
        }

        return $tenantId;
    }
}
