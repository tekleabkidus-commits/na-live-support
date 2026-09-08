<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 14 (SCHEMA AMENDMENT 8, S14 step 2b) — FR-14 / R-05: the PHONE-keyed opt-out registry, append-only by grant (only the restoration triple is updatable). A live row = restored_at IS NULL. phone is PII: $hidden.
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
}
