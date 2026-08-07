<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tickets\Transitions\TicketStatus;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A ticket (Doc 06): the bounded unit of support work inside a permanent conversation.
 *
 * WRITE ACCESS IS REPOSITORY-ONLY, and `status` is narrower still: only
 * App\Domain\Tickets\Storage\TicketRepository::writeStatus() may change it, and only
 * TicketTransitionService may call that (T-01/NFR-03). `status` is deliberately ABSENT
 * from the fillable list below, so a mass-assignment path to it does not exist —
 * a structural bar, not a convention, backed by the `ticket-status-writer` CI gate and
 * StatusWriterEncapsulationTest.
 *
 * The FR-24 metric columns are absent from fillable for a different reason: the app
 * role cannot write them AT ALL (R-19b column grants), and the database's own trigger
 * stamps them. Listing them would be a lie about what this model can do.
 *
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $customer_id
 * @property int $number
 * @property string|null $subject
 * @property string|null $subject_source
 * @property string $status
 * @property string $priority
 * @property string $source
 * @property string|null $assignee_id
 * @property string|null $department_id
 * @property array<string, mixed>|null $escalation
 * @property string|null $pending_reason
 * @property string|null $spam_marked_by
 * @property Carbon|null $spam_marked_at
 * @property int $reopened_count
 * @property bool $channel_unreachable
 * @property bool $linked_work
 * @property string|null $origin_ticket_id
 * @property string|null $previous_ticket_id
 * @property string|null $merged_into
 * @property string|null $parent_ticket_id
 * @property array<string, mixed>|null $form_data
 * @property list<string> $tags
 * @property Carbon|null $first_response_at
 * @property Carbon|null $agent_first_response_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property int $waiting_customer_seconds
 * @property int $waiting_staff_seconds
 * @property Carbon $last_status_changed_at
 * @property string|null $first_response_message_id
 * @property string|null $agent_first_response_message_id
 */
#[Fillable([
    'conversation_id',
    'customer_id',
    'number',
    'subject',
    'subject_source',
    'priority',
    'source',
    'assignee_id',
    'department_id',
    'pending_reason',
    'channel_unreachable',
    'linked_work',
    'origin_ticket_id',
    'previous_ticket_id',
    'parent_ticket_id',
    'form_data',
])]
final class Ticket extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'tickets';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'escalation' => 'array',
            'form_data' => 'array',
            'number' => 'int',
            'reopened_count' => 'int',
            'channel_unreachable' => 'bool',
            'linked_work' => 'bool',
            'waiting_customer_seconds' => 'int',
            'waiting_staff_seconds' => 'int',
            'spam_marked_at' => 'immutable_datetime',
            'first_response_at' => 'immutable_datetime',
            'agent_first_response_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'last_status_changed_at' => 'immutable_datetime',
        ];
    }

    public function status(): TicketStatus
    {
        $status = $this->getAttribute('status');

        if (! is_string($status)) {
            throw new LogicException('Ticket has no string status — the column is NOT NULL with a CHECK over the eight fixed statuses (R-01).');
        }

        return TicketStatus::from($status);
    }

    /** The primary key as the string it always is (C-01). */
    public function identifier(): string
    {
        $key = $this->getKey();

        if (! is_string($key) || $key === '') {
            throw new LogicException('Ticket has no string primary key — C-01 requires a UUIDv7.');
        }

        return $key;
    }
}
