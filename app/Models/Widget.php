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
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function key(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'key_id');
    }
}
