<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One {channel, external user} → customer match row (Doc 04 FR-10 step 3's seam
 * storage). Unique per (tenant, channel, external_user_id); many identities may point
 * at one customer — the shape Doc 08's MERGE will use, never exercised this session.
 *
 * @property string $customer_id
 * @property string $channel
 * @property string $external_user_id
 */
#[Fillable([
    'customer_id',
    'channel',
    'external_user_id',
])]
final class CustomerIdentity extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'customer_identities';

    /** The primary key as the string it always is (C-01). */
    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new LogicException('CustomerIdentity has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }
}
