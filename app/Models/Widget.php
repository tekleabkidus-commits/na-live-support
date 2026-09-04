<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chat widget's Doc 02-owned record: which key it answers to, which origins
 * may open a visitor session (Doc 02 FR-21, AC-06).
 *
 * Deliberately minimal — the migration docblock carries the ownership
 * argument. Doc 13 adds its config columns here expand-contract; nothing in
 * Doc 02 may grow this model beyond the allowlist without reopening the
 * step-10 ruling.
 *
 * @property string $key_id
 * @property list<string> $allowed_origins
 */
#[Fillable([
    'key_id',
    'allowed_origins',
    // SCHEMA AMENDMENT 7 item 1 (P-WIDGET, countersigned 2026-09-04): Doc 13's
    // config columns, expand-contract — the growth this docblock reserved.
    // identity_secret_rotated_at is deliberately ABSENT: the DB trigger stamps
    // it from the database clock (C1, C-11) — the app never writes it.
    'name',
    'live_version_id',
    'bundle_channel',
    'test_mode',
    'identity_secret_ref',
    'identity_secret_previous_ref',
    'deleted_at',
])]
final class Widget extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'widgets';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'test_mode' => 'bool',
            'identity_secret_rotated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function key(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'key_id');
    }
}
