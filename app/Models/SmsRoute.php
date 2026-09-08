<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-08: an ordered routing row {country_prefix (E.164 +digits), primary, fallbacks, sender override}; the resolver is longest-prefix (EC-01); a removed route is active = false.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $country_prefix
 * @property int $position
 * @property string $primary_gateway_id
 * @property list<string> $fallback_gateway_ids
 * @property string|null $sender_id_override
 * @property bool $active
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
#[Fillable([
    'country_prefix',
    'position',
    'primary_gateway_id',
    'fallback_gateway_ids',
    'sender_id_override',
    'active',
])]
final class SmsRoute extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** R-19b: created_at is the DB clock's (app-DENIED on INSERT) — Eloquent never writes it; updated_at stays the model's. */
    public const CREATED_AT = null;

    protected $table = 'sms_routes';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'bool',
            'fallback_gateway_ids' => 'array',
        ];
    }

    /** The string primary key (the house accessor every tenant model exposes). */
    public function identifier(): string
    {
        $key = $this->getKey();
        if (! is_string($key)) {
            throw new \LogicException(self::class.' was loaded without a string id; HasUuids should have set one.');
        }

        return $key;
    }
}
