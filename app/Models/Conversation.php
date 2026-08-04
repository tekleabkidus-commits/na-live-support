<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A conversation (Doc 04 FR-01..FR-04): the permanent per-customer-per-channel-endpoint
 * thread. Never deleted (FR-03 — REVOKE DELETE physics, ADR 0003); `blocked` stops
 * outbound and quarantines inbound (FR-03/FR-11). A ticket (Doc 06) is a bounded work
 * item REFERENCING this thread — no ticket semantics live here, only the one-open-
 * ticket invariant's `open_ticket_id` (FR-04/BR-05, claimed atomically in
 * ConversationRepository).
 *
 * Write access is repository-only (T-01/R-05): controllers, jobs and listeners go
 * through App\Domain\Conversations\Storage\ConversationRepository — the structural
 * test in ConversationSchemaTest greps for strays.
 *
 * @property string $channel
 * @property string $channel_ref_id
 * @property string|null $business_connection_id
 * @property string $customer_id
 * @property string $external_chat_id
 * @property string $state
 * @property string|null $open_ticket_id
 * @property Carbon|null $last_customer_message_at
 * @property Carbon|null $last_outbound_at
 * @property int $unread_count
 */
#[Fillable([
    'channel',
    'channel_ref_id',
    'customer_id',
    'external_chat_id',
    'state',
])]
final class Conversation extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'conversations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_customer_message_at' => 'immutable_datetime',
            'last_outbound_at' => 'immutable_datetime',
            'unread_count' => 'int',
        ];
    }

    public function isBlocked(): bool
    {
        return $this->state === 'blocked';
    }

    /** The primary key as the string it always is (C-01). */
    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new LogicException('Conversation has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }
}
