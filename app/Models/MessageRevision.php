<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A retained original behind an edit or delete (Doc 04 FR-21/FR-22, BR-03).
 * Append-only by grant physics (REVOKE UPDATE, DELETE); read access is gated
 * `conversations.history.full` and AUDITED (AC-06) — the gate lives on the
 * history route, the physics live here.
 *
 * @property string $message_id
 * @property string $conversation_id
 * @property string $revision_kind
 * @property string $source
 * @property string|null $actor_id
 * @property string|null $prior_body
 * @property list<array<string, mixed>>|null $prior_media
 */
#[Fillable([
    'message_id',
    'message_created_at',
    'conversation_id',
    'revision_kind',
    'source',
    'actor_id',
    'prior_body',
    'prior_media',
])]
final class MessageRevision extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'message_revisions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'prior_media' => 'array',
            'message_created_at' => 'immutable_datetime',
        ];
    }

    /** The primary key as the string it always is (C-01). */
    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new LogicException('MessageRevision has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }
}
