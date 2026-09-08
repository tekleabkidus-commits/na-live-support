<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-07 / T-02: a gateway record. credential_ref / dlr_key_ref / dlr_secret_ref are vault://sms/ REFERENCES (C-13, the CHECK) — never a value; the config jsonb holds the http_generic MAPPING + rate card + sender rules (no secret key, the CHECK). circuit_opened_at is the trigger's. is_fixture marks the seeded mocks — never proof of a live row.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $driver
 * @property array<string, mixed> $config
 * @property string|null $credential_ref
 * @property string|null $dlr_key_ref
 * @property string|null $dlr_secret_ref
 * @property string|null $webhook_credential_id
 * @property string|null $sender_id
 * @property string $health
 * @property string $circuit_state
 * @property \Carbon\CarbonImmutable|null $circuit_opened_at
 * @property bool $active
 * @property bool $is_fixture
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
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

    /** R-19b: created_at is the DB clock's (app-DENIED on INSERT) — Eloquent never writes it; updated_at stays the model's. */
    public const CREATED_AT = null;

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
