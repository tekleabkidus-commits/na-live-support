<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-07 / T-02: a gateway record. credential_ref / dlr_key_ref / dlr_secret_ref are vault://sms/ REFERENCES (C-13, the CHECK) — never a value; the config jsonb holds the http_generic MAPPING + rate card + sender rules (no secret key, the CHECK). circuit_opened_at is the trigger's. is_fixture marks the seeded mocks — never proof of a live row.
 */
#[Fillable([
    'name',
    'driver',
    'config',
    'credential_ref',
    'dlr_key_ref',
    'dlr_secret_ref',
    'webhook_credential_id',
    'sender_id',
    'health',
    'circuit_state',
    'active',
    'is_fixture',
    'deleted_at',
])]
final class SmsGateway extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'sms_gateways';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'bool',
            'circuit_opened_at' => 'immutable_datetime',
            'config' => 'array',
            'deleted_at' => 'immutable_datetime',
            'is_fixture' => 'bool',
        ];
    }
}
