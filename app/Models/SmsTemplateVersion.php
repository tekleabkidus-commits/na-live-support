<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-04 version history: INSERT-only by grant (immutable; a rollback is a NEW row + the pointer flip); published_at is the DB clock's (R-19b).
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

    protected $table = 'sms_template_versions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'variables_used' => 'array',
        ];
    }
}
