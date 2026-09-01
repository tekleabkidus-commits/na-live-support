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
     * PACKET P-DOORS clause 2 (S12, countersigned): the SYSTEM tag arms — Doc 12
     * FR-03's add/remove. No User travels; the SEC-01 floor is the gate; the
     * audit carries actor system + rule_ref.
     *
     * @param  list<string>  $tags
     */
    public function addBySystem(Ticket $ticket, array $tags, string $ruleRef): Ticket
    {
        return $this->apply($ticket, $this->normalise($tags), null, 'ticket.tagged', $ruleRef);
    }

    /** @param list<string> $tags */
    public function removeBySystem(Ticket $ticket, array $tags, string $ruleRef): Ticket
    {
        return $this->apply($ticket, $this->normalise($tags), null, 'ticket.untagged', $ruleRef);
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
     * THE parsed read for the `tags` column. The attribute on the model is the raw
     * Postgres text[] literal (`{a,b,"c d"}`) because no Eloquent cast exists — and
     * hand-parsing that literal breaks on FR-19's free text (commas, quotes), so the
     * database serializes it to JSON instead.
     *
     * @return list<string>
     */
    public function tagsOf(Ticket $ticket): array
    {
        /** @var object{tags: string|null}|null $row */
        $row = DB::selectOne('SELECT array_to_json(tags) AS tags FROM tickets WHERE id = ?', [$ticket->identifier()]);

        if ($row === null || $row->tags === null) {
            return [];
        }

        /** @var list<string> $tags */
        $tags = json_decode($row->tags, true, 512, JSON_THROW_ON_ERROR);

        return $tags;
    }

    /**
     * FR-20's tags union into the merge target. No tickets.tags.manage check — the
     * union is a defined consequence of the merge itself (tickets.merge governs the
     * whole operation, A-13), not a separate tagging act. The standard ticket.tagged
     * event and audit ride via apply(), same as any other tag write.
     *
     * @param  list<string>  $tags
     */
    public function unionForMerge(Ticket $target, array $tags, User $actor): void
    {
        $this->apply($target, $this->normalise($tags), $actor, 'ticket.tagged');
    }

    /**
     * AC-04's restore: set the target's tags to exactly the snapshot array
     * (FR-20/A-13). Verbatim assignment rather than set arithmetic, because the bar
     * is byte-identical; emits ticket.untagged with the shed count when the set
     * shrank, so Doc 15's tag surfaces see the change.
     *
     * @param  list<string>  $tags
     */
    public function resetForUnmerge(Ticket $target, array $tags): void
    {
        $current = $this->tagsOf($target);
        $shed = array_values(array_diff($current, $tags));

        if ($tags === []) {
            DB::update("UPDATE tickets SET tags = '{}'::text[], updated_at = clock_timestamp() WHERE id = ?", [$target->identifier()]);
        } else {
            $placeholders = implode(', ', array_fill(0, count($tags), '?'));
            DB::update(
                "UPDATE tickets SET tags = ARRAY[{$placeholders}]::text[], updated_at = clock_timestamp() WHERE id = ?",
                [...$tags, $target->identifier()],
            );
        }
        $target->refresh();

        if ($shed !== []) {
            $this->events->publish('ticket.untagged', [
                'tenant_id' => $target->getAttribute('tenant_id'),
                'ticket_id' => $target->identifier(),
                'conversation_id' => $target->conversation_id,
                'count' => count($shed),
                'actor_id' => null,
            ]);

            $this->audit->record(
                actionKey: 'ticket.untagged',
                entity: 'ticket',
                entityId: $target->identifier(),
                actorType: AuditActor::System,
                payload: ['ticket_id' => $target->identifier(), 'tags' => $shed, 'reason' => 'unmerge_restore'],
            );
        }
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
    private function apply(Ticket $ticket, array $tags, ?User $actor, string $event, ?string $ruleRef = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $tags, $actor, $event, $ruleRef): Ticket {
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
                'actor_id' => $actor?->identifier(),
            ]);

            $this->audit->record(
                actionKey: $event,
                entity: 'ticket',
                entityId: $ticket->identifier(),
                actorType: $actor instanceof User ? AuditActor::Staff : AuditActor::System,
                payload: ['ticket_id' => $ticket->identifier(), 'tags' => $tags] + ($ruleRef === null ? [] : ['rule_ref' => $ruleRef]),
                actorId: $actor?->identifier(),
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
