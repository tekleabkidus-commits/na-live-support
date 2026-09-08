<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-04: a template head — event + language, the pointer to its PUBLISHED version (FR-04 "sends always use the published version"), the quiet-hours policy (exempt|hold|skip), the on_missing policy (FR-06). event/language are UPDATE-DENIED by grant (a template IS its event).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event
 * @property string $language
 * @property string $name
 * @property bool $active
 * @property string $quiet_hours_policy
 * @property string $on_missing
 * @property string|null $fallback_text
 * @property string|null $live_version_id
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
#[Fillable([
    'event',
    'language',
    'name',
    'active',
    'quiet_hours_policy',
    'on_missing',
    'fallback_text',
    'live_version_id',
    'deleted_at',
])]
final class SmsTemplate extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** R-19b: created_at is the DB clock's (app-DENIED on INSERT) — Eloquent never writes it; updated_at stays the model's. */
    public const CREATED_AT = null;

    protected $table = 'sms_templates';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'bool',
            'deleted_at' => 'immutable_datetime',
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
