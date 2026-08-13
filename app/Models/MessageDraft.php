<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A server-side draft (Doc 04 FR-20, T-07): one row per (user, conversation),
 * last-writer-wins on the updated_at guard (EC-06). Cleared on send by SOFT clear
 * (body NULL + cleared_at) — ADR 0003 revokes DELETE, and the row is reused.
 * `draft.saved` is local-only: nothing about this model touches the event bus.
 *
 * `cleared_at` is fillable for the SERVICE's un-tombstone (a re-draft after a
 * send reuses the row; save() writes cleared_at=null) — ruled core edit #7:
 * without it, fill() silently dropped the clear and the reused row stayed
 * tombstoned forever. The model is written only via DraftStore (sole-writer
 * grep in the evidence packet), so nothing else can mass-assign it.
 *
 * @property string $user_id
 * @property string $conversation_id
 * @property string|null $body
 */
#[Fillable([
    'user_id',
    'conversation_id',
    'body',
    'cleared_at',
])]
final class MessageDraft extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'message_drafts';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cleared_at' => 'immutable_datetime'];
    }

    /** The primary key as the string it always is (C-01). */
    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new LogicException('MessageDraft has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }
}
