<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered Telegram bot (Doc 05 FR-01; migration docblock carries the
 * schema design; ruling A-19 carries the token posture).
 *
 * `bot_token` is the A-19 interim store — the webhook_credentials.hmac_secret
 * posture verbatim: `encrypted` cast (DB holds ciphertext, serialization
 * holds ciphertext), `$hidden` on top, the secrets-never-serialize roster
 * pin, and SEC-01's UI rule (never displayed; re-entry to change). The C-13
 * vault handoff is filed in ANSWERS A-19: on the Doc 18 vault this becomes a
 * vault reference by expand-contract migration.
 *
 * `created_at` is DB time the app cannot write (R-19b) — absent from
 * Fillable BY DESIGN; adding it would die on the column grant, not on review.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $telegram_bot_id
 * @property string $username
 * @property string $display_name
 * @property string|null $bot_token
 * @property string|null $key_id
 * @property string $mode
 * @property string $status
 */
#[Fillable([
    'telegram_bot_id',
    'username',
    'display_name',
    'bot_token',
    'key_id',
    'mode',
    'status',
])]
final class TelegramBot extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'telegram_bots';

    /**
     * The Ticket precedent verbatim: a null CREATED_AT keeps `created_at`
     * out of Eloquent's INSERT payload so the R-19b clock_timestamp()
     * default fills it — the column grant refuses it otherwise (proven
     * live: the first creation run of TelegramBotApiTest failed with
     * `permission denied for table telegram_bots` before this constant).
     */
    public const CREATED_AT = null;

    /** @var list<string> */
    protected $hidden = [
        'bot_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'telegram_bot_id' => 'int',
            'bot_token' => 'encrypted',
        ];
    }

    /** @return BelongsTo<TenantKey, $this> */
    public function key(): BelongsTo
    {
        return $this->belongsTo(TenantKey::class, 'key_id');
    }
}
