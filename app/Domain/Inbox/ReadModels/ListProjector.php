<?php

declare(strict_types=1);

namespace App\Domain\Inbox\ReadModels;

use App\Domain\Inbox\Realtime\CountPusher;
use App\Domain\Inbox\Realtime\ViewDeltaPusher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The list-projector (Doc 07 T-01, R-01's "hardest engineering in the
 * series" — kept boring on purpose): an event is a TRIGGER naming which
 * tickets to recompute; every value comes from the canonical rows
 * (TicketListItems::recompute), and view membership is re-evaluated from the
 * fresh row against SystemViews' definitions. Redelivery recomputes the same
 * state — C-09 BY CONSTRUCTION (proven, and sabotaged, in
 * ListProjectorTest). Since step 3 every membership change ALSO publishes a
 * T-02 view delta (add|patch|remove with the integer-µs sort_key and a row
 * patch) plus the debounced T-03 count push — ephemeral protocol v:1 through
 * ViewDeltaPusher, never the outbox (the RealtimePusher distinction).
 *
 * Membership sync per apply:
 *  - my_open:{user}: the OLD row's assignee (read before recompute) loses
 *    the ticket when it no longer qualifies; the new assignee gains it.
 *  - unassigned / sla_at_risk / expiring_windows / rescue (base sets):
 *    add-or-remove from the fresh row's predicate result.
 *  - following:{user}: synced from ticket_followers truth (the follower
 *    events name the user; activity events rescore every follower's entry).
 *  - mentions:{user}: note.mentioned mints the bell row (insertOrIgnore
 *    against the event-dedupe index — C-09 as physics) and adds membership;
 *    resolution (A-32) clears it via read_at + zrem (steps 9/12 wire the
 *    doors; markResolved() is the one they call).
 *
 * @phpstan-import-type ListItemRow from TicketListItems
 */
final class ListProjector
{
    public function __construct(
        private readonly TicketListItems $items,
        private readonly ViewZsets $zsets,
        private readonly ViewDeltaPusher $pusher,
        private readonly CountPusher $counts,
    ) {}

    /**
     * @param  object{id: string, tenant_id: string, name: string, payload: string}  $event
     */
    public function applyEvent(object $event): void
    {
        /** @var array<string, mixed> $payload */
        $payload = (array) json_decode($event->payload, true, 64, JSON_THROW_ON_ERROR);
        $name = $event->name;

        if ($name === 'note.mentioned') {
            $this->applyMention($event->id, $payload);

            return;
        }

        if (in_array($name, ['telegram.connection.revoked', 'telegram.connection.active'], true)) {
            // The Doc 05 writer already flipped tickets.channel_unreachable in
            // its own transaction; re-sync every affected ticket from truth.
            foreach ($this->rescueAffectedTickets($payload) as $ticketId) {
                $this->recomputeTicket($ticketId);
            }

            return;
        }

        foreach ($this->affectedTicketIds($name, $payload) as $ticketId) {
            $this->recomputeTicket($ticketId);
        }
    }

    /** Recompute one ticket's row and re-evaluate every membership it touches. */
    public function recomputeTicket(string $ticketId): void
    {
        /** @var object{assignee_id: string|null}|null $before */
        $before = DB::table('ticket_list_items')->where('ticket_id', $ticketId)->select('assignee_id')->first();

        $row = $this->items->recompute($ticketId);
        if ($row === null) {
            return;
        }

        $open = in_array($row->status, SystemViews::OPEN_FAMILY, true);
        $activityMicros = ViewZsets::toMicros((string) $row->last_activity_at);

        // my_open — the old assignee's set first (a reassignment must REMOVE).
        $oldAssignee = $before->assignee_id ?? null;
        if ($oldAssignee !== null && $oldAssignee !== $row->assignee_id) {
            $this->syncOut(SystemViews::userView('my_open', $oldAssignee), $ticketId);
        }
        if ($row->assignee_id !== null) {
            $view = SystemViews::userView('my_open', $row->assignee_id);
            $open
                ? $this->syncIn($view, $ticketId, $activityMicros, $row)
                : $this->syncOut($view, $ticketId);
        }

        // unassigned — first-come: score is the TICKET's birth stamp (BR-04).
        ($open && $row->assignee_id === null)
            ? $this->syncIn('unassigned', $ticketId, ViewZsets::toMicros((string) $row->ticket_created_at), $row)
            : $this->syncOut('unassigned', $ticketId);

        // sla_at_risk — seam columns (fixture-fed until Doc 12; Amendment rider (ii)).
        ($open && in_array($row->sla_state, ['warned', 'breached'], true) && $row->sla_due_at !== null)
            ? $this->syncIn('sla_at_risk', $ticketId, ViewZsets::toMicros((string) $row->sla_due_at), $row)
            : $this->syncOut('sla_at_risk', $ticketId);

        // expiring_windows — Doc 05 FR-22's closing/expired with open tickets.
        /** @var string|null $windowStateRaw */
        $windowStateRaw = DB::table('conversations')->where('id', $row->conversation_id)->value('reply_window_state');
        $windowState = $windowStateRaw ?? '';
        ($open && in_array($windowState, ['closing', 'expired'], true) && $row->window_expires_at !== null)
            ? $this->syncIn('expiring_windows', $ticketId, ViewZsets::toMicros((string) $row->window_expires_at), $row)
            : $this->syncOut('expiring_windows', $ticketId);

        // rescue — channel_unreachable, any status (BR-07 keeps them open in practice).
        $row->channel_unreachable
            ? $this->syncIn('rescue', $ticketId, $activityMicros, $row)
            : $this->syncOut('rescue', $ticketId);

        // following — membership from truth; activity rescores every follower.
        /** @var list<string> $followers */
        $followers = DB::table('ticket_followers')->where('ticket_id', $ticketId)->pluck('user_id')->all();
        foreach ($followers as $followerId) {
            $this->syncIn(SystemViews::userView('following', $followerId), $ticketId, $activityMicros, $row);
        }
        // A follower REMOVAL is synced when its event names the user (below);
        // recompute alone cannot know who left, and scanning every user's set
        // would be the stampede T-01 forbids.
    }

    /**
     * Membership add/rescore + the T-02 delta + the T-03 count, in one place.
     *
     * @param  ListItemRow  $row
     */
    private function syncIn(string $viewKey, string $ticketId, int $scoreMicros, object $row): void
    {
        $isNew = $this->zsets->add($viewKey, $ticketId, $scoreMicros);

        $this->pusher->toViewKey($viewKey, 'inbox.view_delta', [
            'type' => 'view_delta',
            'view_key' => $viewKey,
            'op' => $isNew ? 'add' : 'patch',
            'id' => $ticketId,
            'sort_key' => $scoreMicros,
            // Assembled in the context-free file (the status-writer gate's
            // design constraint — see TicketListItems::patchFor).
            'patch' => TicketListItems::patchFor($row),
        ]);
        $this->counts->onDelta($viewKey);
    }

    private function syncOut(string $viewKey, string $ticketId): void
    {
        if (! $this->zsets->remove($viewKey, $ticketId)) {
            return; // was never a member — no delta, no count churn (T-02 precision)
        }

        $this->pusher->toViewKey($viewKey, 'inbox.view_delta', [
            'type' => 'view_delta',
            'view_key' => $viewKey,
            'op' => 'remove',
            'id' => $ticketId,
        ]);
        $this->counts->onDelta($viewKey);
    }

    /** @param array<string, mixed> $payload */
    private function applyMention(string $eventId, array $payload): void
    {
        $ticketId = $this->ticketForConversation(self::str($payload, 'conversation_id'));
        if ($ticketId === null) {
            return; // a mention in a conversation with no ticket has no list row to join
        }

        // C-09 as physics: the event-dedupe index makes the second delivery a no-op.
        DB::table('inbox_notifications')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => self::str($payload, 'tenant_id'),
            'user_id' => self::str($payload, 'mentioned_user_id'),
            'category' => 'notify.inbox.mention',
            'ticket_id' => $ticketId,
            'conversation_id' => self::str($payload, 'conversation_id'),
            'payload' => json_encode(['event_id' => $eventId], JSON_THROW_ON_ERROR),
        ]);

        /** @var object{created_at: string}|null $rowStamp */
        $rowStamp = DB::table('inbox_notifications')
            ->where('user_id', self::str($payload, 'mentioned_user_id'))
            ->whereRaw("payload->>'event_id' = ?", [$eventId])
            ->select('created_at')->first();

        if ($rowStamp !== null) {
            $view = SystemViews::userView('mentions', self::str($payload, 'mentioned_user_id'));
            $isNew = $this->zsets->add($view, $ticketId, ViewZsets::toMicros($rowStamp->created_at));
            $this->pusher->toViewKey($view, 'inbox.view_delta', [
                'type' => 'view_delta',
                'view_key' => $view,
                'op' => $isNew ? 'add' : 'patch',
                'id' => $ticketId,
                'sort_key' => ViewZsets::toMicros($rowStamp->created_at),
            ]);
            $this->counts->onDelta($view);
        }
    }

    /**
     * A-32's resolution door (thread open / bell dismiss — steps 9/12 call it):
     * latch read_at on the user's unresolved mention rows for the ticket and
     * drop the membership when none remain.
     */
    public function markMentionsResolved(string $userId, string $ticketId): void
    {
        DB::table('inbox_notifications')
            ->where('user_id', $userId)
            ->where('ticket_id', $ticketId)
            ->where('category', 'notify.inbox.mention')
            ->whereNull('read_at')
            ->update(['read_at' => DB::raw('clock_timestamp()')]);

        $this->syncOut(SystemViews::userView('mentions', $userId), $ticketId);
    }

    /**
     * Which tickets an event names — ids only; every VALUE comes from truth.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function affectedTicketIds(string $name, array $payload): array
    {
        // Follower removal must sync the named user's set (recompute cannot
        // know who left).
        if ($name === 'ticket.follower_removed') {
            $this->syncOut(
                SystemViews::userView('following', self::str($payload, 'user_id')),
                self::str($payload, 'ticket_id'),
            );
        }

        return array_values(array_filter(match ($name) {
            'ticket.merged', 'ticket.unmerged' => [self::str($payload, 'source_ticket_id'), self::str($payload, 'target_ticket_id')],
            'ticket.split' => [self::str($payload, 'origin_ticket_id'), self::str($payload, 'new_ticket_id')],
            'message.received', 'message.sent',
            'telegram.window.closing', 'telegram.window.expired', 'telegram.window.reopened' => [$this->ticketForConversation(self::str($payload, 'conversation_id'))],
            default => [isset($payload['ticket_id']) ? self::str($payload, 'ticket_id') : null],
        }));
    }

    private function ticketForConversation(string $conversationId): ?string
    {
        /** @var string|null $id */
        $id = DB::table('conversations')->where('id', $conversationId)->value('open_ticket_id');
        if ($id !== null) {
            return $id;
        }

        /** @var string|null $latest */
        $latest = DB::table('tickets')->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')->orderByDesc('id')->value('id');

        return $latest;
    }

    /**
     * A required string payload key (closed catalog payloads make absence a defect).
     *
     * @param  array<string, mixed>  $payload
     */
    private static function str(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("event payload key {$key} is not a non-empty string");
        }

        return $value;
    }

    /**
     * Tickets whose conversations ride the connection a revoke/active names —
     * bounded by the connection's own conversation set, read from truth.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function rescueAffectedTickets(array $payload): array
    {
        // The event carries Telegram's connection id string (FR-09's payload);
        // conversations.business_connection_id stores the same value — the
        // ChannelUnreachableWriter's own join, reused.
        $connectionId = isset($payload['connection_id']) && is_string($payload['connection_id']) ? $payload['connection_id'] : null;

        $query = DB::table('tickets as t')->join('conversations as c', 'c.id', '=', 't.conversation_id');

        if ($connectionId !== null) {
            $query->where('c.business_connection_id', $connectionId);
        } else {
            // No connection id in the payload: fall back to every flagged ticket
            // plus every rescue member (so recoveries clear) — still bounded.
            /** @var list<string> $flagged */
            $flagged = DB::table('tickets')->where('channel_unreachable', true)->pluck('id')->all();
            $members = $this->zsets->allIds('rescue');

            return array_values(array_unique(array_merge($flagged, $members)));
        }

        /** @var list<string> $ids */
        $ids = $query->pluck('t.id')->all();

        return $ids;
    }
}
