<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Conversations\Messages\MessageKind;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A message row (Doc 04 FR-05/FR-06) on the monthly-partitioned `messages` table.
 *
 * PARTITIONED-TABLE RULES (T-01, Doc 01 R-05):
 * - ALL reads and writes go through App\Domain\Conversations\Storage\MessageRepository.
 *   Ad-hoc `Message::query()` outside that namespace is the exact defect the
 *   structural test greps for — an unbounded query here scans thirteen months.
 * - The SQL primary key is (id, created_at); `id` alone remains the UUIDv7 identity
 *   for the model (Eloquent addressing always carries created_at via the repository,
 *   which is why casual save()/find() outside it is unsafe on top of being banned).
 *
 * `is_internal` mirrors kind='note' by CHECK constraint — the row-level shadow of
 * SEC-07's DeliverablePayload physics. `channel_occurred_at` is BR-04's metadata-only
 * channel clock; display order is (created_at, id), always.
 *
 * @property string $conversation_id
 * @property string $direction
 * @property string $author_type
 * @property string|null $author_id
 * @property string $kind
 * @property string|null $body
 * @property list<array<string, mixed>> $media
 * @property string|null $language
 * @property string|null $external_message_id
 * @property string|null $reply_to_message_id
 * @property string|null $delivery_state
 * @property array<string, mixed>|null $delivery_error
 * @property bool $is_internal
 * @property bool $quarantined
 * @property string|null $client_idempotency_key
 * @property array<string, mixed> $channel_meta
 */
#[Fillable([
    'conversation_id',
    'direction',
    'author_type',
    'author_id',
    'kind',
    'body',
    'media',
    'language',
    'external_message_id',
    'reply_to_message_id',
    'delivery_state',
    'is_internal',
    'quarantined',
    'client_idempotency_key',
    'channel_meta',
    'channel_occurred_at',
])]
final class Message extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'messages';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'media' => 'array',
            'delivery_error' => 'array',
            'is_internal' => 'bool',
            'quarantined' => 'bool',
            'channel_meta' => 'array',
            'channel_occurred_at' => 'immutable_datetime',
            'edited_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function kindEnum(): MessageKind
    {
        return MessageKind::from($this->kind);
    }

    /** The primary key as the string it always is (C-01). */
    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new LogicException('Message has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }
}
