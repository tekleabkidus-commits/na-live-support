<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Tags;

use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * FR-19: "Tags: free-form per tenant with autocomplete; bulk-manageable; tag
 * rename/merge is an admin background operation propagating historically, audited with
 * counts."
 *
 * TAG CONTENT IS NEVER TRANSLATED (§14: "tag content is operator free-text with no
 * translation layer, by design"). It reaches the column verbatim, through bindings —
 * never string-concatenated into SQL, because operator free text is exactly the input
 * that must not be trusted into a query.
 *
 * The array is a Postgres `text[]` with a GIN index: FR-19's autocomplete and T-07's
 * historical rewrite both read it, and §12 wants autocomplete inside 80 ms at 5k
 * distinct tags, which an unindexed array scan would not give.
 */
final class TicketTags
{
    private const MAX_LENGTH = 64;

    public function __construct(
        private readonly CapabilityMapProvider $verdicts,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $tags
     */
    public function add(Ticket $ticket, array $tags, User $actor): Ticket
    {
        $this->requirePermission($actor);
        $clean = $this->normalise($tags);

        return $this->apply($ticket, $clean, $actor, 'ticket.tagged');
    }

    /**
     * @param  list<string>  $tags
     */
    public function remove(Ticket $ticket, array $tags, User $actor): Ticket
    {
        $this->requirePermission($actor);
        $clean = $this->normalise($tags);

        return $this->apply($ticket, $clean, $actor, 'ticket.untagged');
    }

    /**
     * FR-19's autocomplete source: the tenant's distinct tags, prefix-matched.
     *
     * Reads only this tenant's tickets — RLS makes that structural rather than a
     * WHERE clause anyone could forget.
     *
     * @return list<string>
     */
    public function suggest(string $prefix, int $limit = 10): array
    {
        /** @var list<object{tag: string}> $rows */
        $rows = DB::select(
            'SELECT DISTINCT unnest(tags) AS tag FROM tickets ORDER BY tag LIMIT ?',
            [max(1, min($limit * 20, 500))],
        );

        $needle = mb_strtolower(trim($prefix));
        $matches = [];
        foreach ($rows as $row) {
            if ($needle === '' || str_starts_with(mb_strtolower($row->tag), $needle)) {
                $matches[] = $row->tag;
            }
            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function normalise(array $tags): array
    {
        $clean = [];
        foreach ($tags as $tag) {
            $trimmed = trim($tag);

            if ($trimmed === '') {
                throw new InvalidArgumentException('a blank tag is not a tag (FR-19).');
            }
            if (mb_strlen($trimmed) > self::MAX_LENGTH) {
                throw new InvalidArgumentException(sprintf('a tag is at most %d characters (FR-19).', self::MAX_LENGTH));
            }

            $clean[] = $trimmed;
        }

        if ($clean === []) {
            throw new InvalidArgumentException('no tags given (FR-19).');
        }

        return array_values(array_unique($clean));
    }

    /** @param list<string> $tags */
    private function apply(Ticket $ticket, array $tags, User $actor, string $event): Ticket
    {
        return DB::transaction(function () use ($ticket, $tags, $actor, $event): Ticket {
            $placeholders = implode(', ', array_fill(0, count($tags), '?'));

            // Set arithmetic in the DATABASE, not in PHP: a read-modify-write here
            // would lose a concurrent tag from the bulk bar (FR-25 tags in batches).
            $sql = $event === 'ticket.tagged'
                ? "UPDATE tickets SET tags = ARRAY(SELECT DISTINCT unnest(tags || ARRAY[{$placeholders}]::text[]) ORDER BY 1), updated_at = clock_timestamp() WHERE id = ?"
                : "UPDATE tickets SET tags = ARRAY(SELECT unnest(tags) EXCEPT SELECT unnest(ARRAY[{$placeholders}]::text[]) ORDER BY 1), updated_at = clock_timestamp() WHERE id = ?";

            DB::update($sql, [...$tags, $ticket->identifier()]);
            $ticket->refresh();

            $this->events->publish($event, [
                'tenant_id' => $ticket->getAttribute('tenant_id'),
                'ticket_id' => $ticket->identifier(),
                'conversation_id' => $ticket->conversation_id,
                // The TAGS themselves are operator free text about a player's issue,
                // so they ride the audit entry rather than the 13-month event stream
                // (Q7's posture). The event says THAT tagging changed; the audit says
                // what to.
                'count' => count($tags),
                'actor_id' => $actor->identifier(),
            ]);

            $this->audit->record(
                actionKey: $event,
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: AuditActor::Staff,
                payload: ['ticket_id' => $ticket->identifier(), 'tags' => $tags],
                actorId: $actor->identifier(),
            );

            return $ticket;
        });
    }

    private function requirePermission(User $actor): void
    {
        if (! $this->verdicts->verdictFor($actor, 'tickets.tags.manage')->allowed) {
            throw new DomainException('tickets.tags.manage is required (FR-19, mapping A-9).');
        }
    }
}
