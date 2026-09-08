<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-14 / R-05: the PHONE-keyed opt-out registry, append-only by grant (only the restoration triple is updatable). A live row = restored_at IS NULL. phone is PII: $hidden.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $phone
 * @property string $source
 * @property string|null $reason
 * @property string|null $added_by
 * @property string|null $stop_word_matched
 * @property \Carbon\CarbonImmutable|null $restored_at
 * @property string|null $restored_by
 * @property string|null $restore_reason
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
#[Fillable([
    'phone',
    'source',
    'reason',
    'added_by',
    'stop_word_matched',
    'restored_at',
    'restored_by',
    'restore_reason',
])]
final class SmsOptOut extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** R-19b: created_at is the DB clock's (app-DENIED on INSERT) — Eloquent never writes it; updated_at stays the model's. */
    public const CREATED_AT = null;

    protected $table = 'sms_optouts';

    /** @var list<string> [D17]-classed PII — read explicitly by the two permissioned UIs only (SEC-02). */
    protected $hidden = ['phone'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'restored_at' => 'immutable_datetime',
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
