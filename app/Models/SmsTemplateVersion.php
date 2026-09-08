<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-04 version history: INSERT-only by grant (immutable; a rollback is a NEW row + the pointer flip); published_at is the DB clock's (R-19b).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $template_id
 * @property int $version
 * @property string $body
 * @property list<string> $variables_used
 * @property string $encoding
 * @property int $segments_estimate
 * @property string $published_by
 * @property \Carbon\CarbonImmutable $published_at
 * @property string $status_at_birth
 * @property string|null $note
 * @property \Carbon\CarbonImmutable $created_at
 */
#[Fillable([
    'template_id',
    'version',
    'body',
    'variables_used',
    'encoding',
    'segments_estimate',
    'published_by',
    'status_at_birth',
    'note',
])]
final class SmsTemplateVersion extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** INSERT-only by grant; created_at/published_at are the DB clock's; there is no updated_at. */
    public $timestamps = false;

    protected $table = 'sms_template_versions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'variables_used' => 'array',
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

    /** @return BelongsTo<SmsTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'template_id');
    }
}
