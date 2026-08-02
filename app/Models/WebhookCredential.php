<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The second credential a webhook surface demands after tenant resolution
 * (Doc 02 FR-23/FR-24; the migration docblock carries the design).
 *
 * `hmac_secret` uses the `encrypted` cast — the TOTP-secret posture. The cast
 * means the plaintext exists only in process memory during verification:
 * the DB holds ciphertext, serialization (jobs, arrays, logs) holds
 * ciphertext, and $hidden keeps it out of any accidental toArray/toJson on
 * top of that. Step 12's log-scrubber seeds a fake through this exact
 * attribute (inherited list, BUILD-STATUS S15).
 *
 * @property string $key_id
 * @property string|null $header_secret_hash
 * @property string|null $hmac_secret
 */
#[Fillable([
    'key_id',
    'header_secret_hash',
    'hmac_secret',
])]
final class WebhookCredential extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'webhook_credentials';

    /** @var list<string> */
    protected $hidden = [
        'header_secret_hash',
        'hmac_secret',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hmac_secret' => 'encrypted',
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function key(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'key_id');
    }
}
