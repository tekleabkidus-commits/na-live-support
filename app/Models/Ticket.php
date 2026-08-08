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
 * @property string|null $tags raw Postgres text[] literal — parse via TicketTags::tagsOf(), never by hand
 * @property Carbon|null $first_response_at
 * @property Carbon|null $agent_first_response_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $auto_close_at
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

    /** Microsecond precision, or the R-19b stamps round-trip as whole seconds. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * DB TIME, NOT APP TIME (owner ruling R-19/R-19b, ADR 0010) — the same shape
     * Message adopted at the messages cutover.
     *
     * A null CREATED_AT makes Eloquent's updateTimestamps() skip setCreatedAt entirely,
     * so `created_at` never enters the insert payload and PostgreSQL's
     * `clock_timestamp()` default fills it. `updated_at` is a separate branch of that
     * same method and is untouched — the app writes that one legitimately.
     *
     * THIS WAS NOT A PRECAUTION, IT WAS A CATCH. The first creation-path run failed with
     * `permission denied for table tickets` because Eloquent had helpfully added
     * `created_at` to the INSERT: the R-19b column grant refused it, loudly, before a
     * single app-clock value could reach an ordering column. That is precisely the
     * discipline-versus-physics distinction R-19a was filed over — a convention would
     * have let it through and nothing would have gone red.
     *
     * Nothing derives its column from this constant: `->latest()`/`->oldest()` appear
     * nowhere against tickets, verified before the change (the same check ADR 0010
     * required for messages).
     */
    public const CREATED_AT = null;

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
            'auto_close_at' => 'immutable_datetime',
            'last_status_changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * READS THE RAW ATTRIBUTE, NEVER getAttribute() — and the difference is not
     * stylistic, it is the difference between an exception and a hang.
     *
     * `status` is absent from the INSERT payload by design (NFR-03: creation lets the
     * column's `new` default do the work so the transition service stays the only
     * writer). A model that has not been refreshed therefore has no `status` key at
     * all, and `getAttribute('status')` responds to a missing key by looking for a
     * RELATION method of that name — which is this method — which calls getAttribute
     * again. That recursion exhausted 512 MB and killed the process with a fatal
     * rather than a failure, twice, before it was diagnosed.
     *
     * getAttributes() reads the array directly, so the missing case surfaces as the
     * named exception below instead of a stack overflow.
     */
    public function status(): TicketStatus
    {
        $status = $this->getAttributes()['status'] ?? null;

        if (! is_string($status)) {
            throw new LogicException(
                'Ticket has no loaded `status` — the column is NOT NULL with a CHECK over the eight '
                .'fixed statuses (R-01), so this model was built without refreshing after an insert '
                .'that deliberately omitted the column.',
            );
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
