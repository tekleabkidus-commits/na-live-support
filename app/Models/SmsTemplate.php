<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-04: a template head — event + language, the pointer to its PUBLISHED version (FR-04 "sends always use the published version"), the quiet-hours policy (exempt|hold|skip), the on_missing policy (FR-06). event/language are UPDATE-DENIED by grant (a template IS its event).
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

    protected $table = 'sms_templates';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'bool',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
