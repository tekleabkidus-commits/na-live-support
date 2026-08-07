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
    /**
     * MICROSECOND PRECISION IS LOAD-BEARING HERE (R-19 / ADR 0010).
     *
     * `message_created_at` is half of the composite FK
     * (message_id, message_created_at) -> messages (id, created_at). Since R-19 moved
     * `messages.created_at` to PostgreSQL's `clock_timestamp()` it carries microseconds,
     * and Eloquent re-serialises this cast attribute with the model's date format on the
     * way back out. Laravel's Postgres grammar defaults that format to whole seconds, so
     * writing a revision truncated the copy, it no longer equalled the row it pointed at,
     * and the FK refused it — "Key is not present in table messages" against a message
     * that plainly existed. Caught by EditDeleteTranscriptTest and NodeKillReplayDrillTest
     * the first time the full suite ran after the cutover.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

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
