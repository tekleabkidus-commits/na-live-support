<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * The SHELL customer the Doc 08 identity seam creates (Doc 04 FR-10 step 3).
 *
 * Doc 08 OWNS this concept: merge rules, shell semantics beyond match-or-create,
 * profile fields, and any richer identity all arrive there (pre-agreed bucket). This
 * session only match-or-creates via CustomerResolver. Every field is staff-collected
 * or channel-reported — NEVER `verified` (invariant N-06; no such column can exist).
 *
 * @property string|null $display_name
 * @property string|null $username
 * @property string|null $phone
 * @property bool $is_shell
 */
#[Fillable([
    'display_name',
    'username',
    'phone',
    'is_shell',
])]
final class Customer extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'customers';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_shell' => 'bool'];
    }

    /** @return HasMany<CustomerIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    /** The primary key as the string it always is (C-01). */
    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new LogicException('Customer has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }
}
