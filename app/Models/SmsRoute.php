<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-08: an ordered routing row {country_prefix (E.164 +digits), primary, fallbacks, sender override}; the resolver is longest-prefix (EC-01); a removed route is active = false.
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

    protected $table = 'sms_routes';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'bool',
            'fallback_gateway_ids' => 'array',
        ];
    }
}
