<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A credential one of the five key-bearing resolution paths presents
 * (Doc 01 FR-03b–e, SEC-03).
 *
 * THE `hash` COLUMN IS UNREADABLE BY THIS ROLE, ON PURPOSE. The RLS migration
 * grants the app role column-level SELECT on everything except `hash`, so
 * `SELECT *` — which is what Eloquent emits by default — fails outright with
 * "permission denied". Rather than let that surface as a confusing runtime error
 * in some future module, the global scope below narrows every query to the
 * readable columns.
 *
 * That is not belt-and-braces, it is the design: verifying a presented secret goes
 * through resolve_tenant_key() in the database, which compares the hash without
 * ever returning it. So there is no code path — correct or buggy, present or
 * future — that can read a stored key hash out of this table. SEC-03 says
 * "plaintext shown once at creation"; this makes the ciphertext no more available
 * than the plaintext.
 */
final class TenantKey extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'tenant_keys';

    /**
     * Every column the app role may read. `hash` is absent and must stay absent.
     *
     * @var list<string>
     */
    public const READABLE_COLUMNS = [
        'id',
        'tenant_id',
        'kind',
        'prefix',
        'label',
        'last_used_at',
        'revoked_at',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    protected $fillable = [
        'kind',
        'prefix',
        'hash',
        'label',
    ];

    /**
     * `hash` is write-only: fillable so a key can be created, hidden so it cannot
     * be serialised into a response, a log or a job payload (C-13).
     *
     * @var list<string>
     */
    protected $hidden = [
        'hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::addGlobalScope('readable_columns', function (Builder $builder): void {
            $builder->select(array_map(
                static fn (string $column): string => 'tenant_keys.'.$column,
                self::READABLE_COLUMNS,
            ));
        });
    }

    public function isRevoked(): bool
    {
        return $this->getAttribute('revoked_at') !== null;
    }
}
