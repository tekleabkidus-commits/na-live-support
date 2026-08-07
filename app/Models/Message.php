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
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $edited_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
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

    /**
     * FULL PRECISION, BOTH WAYS (R-19 §3, ADR 0010).
     *
     * Laravel's Postgres grammar defaults every model's date format to whole seconds,
     * and `Model::setAttribute()` runs a date-cast attribute through that format on
     * ASSIGNMENT — not just on write. So even though the database now assigns
     * `created_at` via `clock_timestamp()` and hands it back through RETURNING, the
     * value was being truncated the moment it was set on the model: the row held
     * 13:54:57.893601 while the model held 13:54:57. Anything copying the model's value
     * into a composite FK (message_revisions.message_created_at) then pointed at a row
     * that did not exist. Caught by EditDeleteTranscriptTest and NodeKillReplayDrillTest.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * DB TIME, NOT APP TIME (owner ruling R-19, ADR 0010).
     *
     * A null CREATED_AT makes Eloquent's updateTimestamps() skip setCreatedAt entirely,
     * so `created_at` never enters the insert payload and PostgreSQL's
     * `clock_timestamp()` default fills it at microsecond precision. `updated_at` is
     * handled by a SEPARATE branch of that same method and is untouched.
     *
     * This is belt to the grant's braces: R-19a revokes the app role's column-level
     * INSERT on `created_at`, so supplying it is impossible rather than merely avoided.
     * Nothing in the codebase uses ->latest()/->oldest() (which derive their column from
     * this constant), verified before the change.
     */
    public const CREATED_AT = null;

    /**
     * Hand back the timestamp the DATABASE chose, in the same round trip.
     *
     * Without this the model's `created_at` is null after create() — a silent null
     * waiting for the first caller that wants one, and Doc 06's FR-24 timestamps and
     * closed-schema event payloads will want one shortly. A refresh() would also work
     * but queries by `id` alone, which cannot prune partitions: at soak volume that is
     * 10 000 unpruned lookups. RETURNING costs nothing extra.
     */
    public function newEloquentBuilder($query): MessageBuilder
    {
        return new MessageBuilder($query);
    }

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
