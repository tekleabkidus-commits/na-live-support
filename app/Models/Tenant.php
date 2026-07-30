<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Foundation\Tenancy\TenantState;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One operator brand instance (Doc 01 FR-01, BR-01).
 *
 * Uses BelongsToTenant with `tenantIdColumn()` overridden to `id`: the tenant IS
 * the row, so "scope to the bound tenant" means "id = the bound tenant". The
 * effect is that a tenant cannot read another tenant's row, cannot count tenants,
 * and cannot discover that a slug is taken — SEC-06's 404-not-403 rule holding at
 * the data layer rather than depending on a controller remembering it.
 *
 * The platform console needs the opposite and gets it through
 * PlatformConnection::run(), which is logged and cannot be entered from a
 * tenant-resolved request.
 *
 * `state` is deliberately NOT mass-assignable and has no setter here. It is
 * changed by TenantLifecycle alone, and the database refuses any other writer
 * (see the transition-guard migration). Doing it in one service is a decision;
 * having the database agree is a guarantee.
 */
final class Tenant extends Model implements TenantScoped
{
    /** @use HasFactory<TenantFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'tenants';

    /**
     * `state` is absent on purpose — see the class docblock.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'region',
        'default_language_code',
        'timezone_name',
        'currency_code',
        'plan_flags',
        'quota_overrides',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'plan_flags' => 'array',
            'quota_overrides' => 'array',
            'state_changed_at' => 'immutable_datetime',
            'provisioned_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function tenantIdColumn(): string
    {
        return 'id';
    }

    /**
     * The tenant id, as a string, or a loud failure.
     *
     * `getKey()` is typed `mixed` on Eloquent, and casting mixed to string is how a
     * null id becomes the empty string and then a query that matches nothing. Every
     * caller in this module goes through here instead, so an unsaved or malformed
     * model fails at the point of use with a message that says which model.
     */
    public function tenantId(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new \LogicException(
                'Tenant has no id: it is unsaved, or its key was overwritten. A tenant id is a UUIDv7 '
                .'assigned before insert (see the createTenant test helper and ProvisionTenant).',
            );
        }

        return $key;
    }

    public function state(): TenantState
    {
        $state = $this->getAttribute('state');

        if (! is_string($state)) {
            $key = $this->getKey();

            throw new \LogicException(sprintf(
                'Tenant %s has no state attribute. The column is NOT NULL with a CHECK constraint, so '
                .'this means the model was built without it — a partial select, or a fresh instance.',
                is_string($key) ? $key : '(no id)',
            ));
        }

        return TenantState::from($state);
    }
}
