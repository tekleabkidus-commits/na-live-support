<?php

declare(strict_types=1);

namespace App\Domain\Inbox\ReadModels;

use App\Domain\Inbox\Realtime\CountPusher;
use App\Domain\Inbox\Realtime\ViewDeltaPusher;
use App\Domain\Inbox\Views\SavedViewFilters;
use App\Domain\Inbox\Views\SavedViews;
use App\Domain\Inbox\Views\ViewVariants;
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
        private readonly ViewVariants $variants,
        private readonly SavedViews $savedViews,
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

        // FR-26's bell fanout (S07 step 12): per-user notification ROWS from
        // existing events — no new event (the approved plan's own words).
        // Rides the same consumer transaction as the list projection; the
        // per-user event-dedupe unique index makes redelivery a no-op (C-09,
        // the mention row's established physics). sla_warning has NO producer
        // until Doc 12 — its rows are fixtures (the category closes the set).
        $this->mintBellRows($event->id, $name, $payload);

        // T-04's event-driven indexer: a SEPARATE queued job per affected
        // ticket (FS-03 isolation — a search outage must never fail the list
        // projection riding this same event). Message events also carry
        // their body to the messages index.
        $messageId = in_array($name, ['message.received', 'message.sent'], true) && isset($payload['message_id']) && is_string($payload['message_id'])
            ? $payload['message_id']
            : null;

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
            dispatch(new \App\Jobs\Inbox\IndexSearchDocuments($event->tenant_id, $ticketId, $messageId));
        }
    }

    /** Recompute one ticket's row and re-evaluate every membership it touches. */
    public function recomputeTicket(string $ticketId, bool $withGroup = true): void
    {
        /** @var object{assignee_id: string|null}|null $before */
        $before = DB::table('ticket_list_items')->where('ticket_id', $ticketId)->select('assignee_id')->first();

        $row = $this->items->recompute($ticketId);
        if ($row === null) {
            return;
        }

        // Doc 05 EC-04 (the closing row, step 9): the customer-group flags —
        // dual_channel on every open-family row of a customer running TWO
        // conversations, older_channel_activity on the rows that are NOT the
        // customer's freshest conversation. Group first, so this ticket's own
        // deltas below carry fresh flags; siblings whose flags flipped are
        // re-synced WITHOUT re-grouping (the depth guard — a group of N needs
        // exactly one group pass).
        if ($withGroup) {
            foreach ($this->recomputeCustomerGroup($row) as $siblingTicketId) {
                $this->recomputeTicket($siblingTicketId, withGroup: false);
            }
            /** @var ListItemRow|null $reread */
            $reread = DB::table('ticket_list_items')->where('ticket_id', $ticketId)->first();
            if ($reread === null) {
                return;
            }
            $row = $reread;
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

        // ── A-30 variants of the shared views: every MATERIALIZED signature
        //    is patched with the same membership decision ∩ its scope
        //    predicate (lazy sets that were never built get nothing — they
        //    build correct on first read).
        $sharedMemberships = [
            'unassigned' => [$open && $row->assignee_id === null, ViewZsets::toMicros((string) $row->ticket_created_at)],
            'sla_at_risk' => [$open && in_array($row->sla_state, ['warned', 'breached'], true) && $row->sla_due_at !== null, $row->sla_due_at !== null ? ViewZsets::toMicros((string) $row->sla_due_at) : 0],
            'expiring_windows' => [$open && in_array($windowState, ['closing', 'expired'], true) && $row->window_expires_at !== null, $row->window_expires_at !== null ? ViewZsets::toMicros((string) $row->window_expires_at) : 0],
            'rescue' => [(bool) $row->channel_unreachable, $activityMicros],
        ];
        foreach ($sharedMemberships as $view => [$inView, $score]) {
            foreach ($this->variants->signatures($view) as $signature) {
                $variantKey = $view.':scope:'.$signature;
                ($inView && ViewVariants::signatureMatches($signature, $row))
                    ? $this->syncIn($variantKey, $ticketId, $score, $row)
                    : $this->syncOut($variantKey, $ticketId);
            }
        }

        // ── Materialized saved views (T-01's incremental patch) + their variants.
        foreach ($this->savedViews->materializedIds() as $savedId) {
            /** @var string|null $filtersJson */
            $filtersJson = DB::table('inbox_saved_views')->where('id', $savedId)->whereNull('deleted_at')->value('filters');
            if ($filtersJson === null) {
                continue; // deleted definition — de-materialization races are benign
            }
            /** @var array<string, mixed> $filters */
            $filters = json_decode($filtersJson, true, 16, JSON_THROW_ON_ERROR);

            $inView = SavedViewFilters::matches($filters, $row);
            $savedKey = 'saved:'.$savedId;
            $inView
                ? $this->syncIn($savedKey, $ticketId, $activityMicros, $row)
                : $this->syncOut($savedKey, $ticketId);

            foreach ($this->variants->signatures($savedKey) as $signature) {
                $variantKey = $savedKey.':scope:'.$signature;
                ($inView && ViewVariants::signatureMatches($signature, $row))
                    ? $this->syncIn($variantKey, $ticketId, $activityMicros, $row)
                    : $this->syncOut($variantKey, $ticketId);
            }
        }

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
     * Doc 05 EC-04's group truth, recomputed from the customer's OWN list
     * rows: "one customer, two conversations … inbox groups by customer with
     * a dual-channel badge; agents are warned when replying in the
     * older-activity conversation." Open-family rows only carry the badge
     * (EC-04's "if both have open tickets"); the older flag rides every
     * badged row whose conversation is not the freshest. Returns the SIBLING
     * ticket ids whose flags changed (the caller re-syncs their views).
     *
     * @param  ListItemRow  $row
     * @return list<string>
     */
    private function recomputeCustomerGroup(object $row): array
    {
        if ($row->customer_id === null) {
            return [];
        }

        /** @var list<object{ticket_id: string, conversation_id: string, status: string, last_activity_at: string|null, dual_channel: bool, older_channel_activity: bool}> $group */
        $group = DB::table('ticket_list_items')
            ->where('customer_id', $row->customer_id)
            ->get(['ticket_id', 'conversation_id', 'status', 'last_activity_at', 'dual_channel', 'older_channel_activity'])
            ->all();

        $openRows = array_values(array_filter($group, fn (object $item): bool => in_array($item->status, SystemViews::OPEN_FAMILY, true)));
        $openConversations = array_unique(array_map(fn (object $item): string => $item->conversation_id, $openRows));
        $dual = count($openConversations) >= 2;

        $freshestMicros = 0;
        foreach ($openRows as $item) {
            if ($item->last_activity_at !== null) {
                $freshestMicros = max($freshestMicros, ViewZsets::toMicros($item->last_activity_at));
            }
        }

        $changedSiblings = [];
        foreach ($group as $item) {
            $isOpen = in_array($item->status, SystemViews::OPEN_FAMILY, true);
            $wantDual = $dual && $isOpen;
            $wantOlder = $wantDual
                && $item->last_activity_at !== null
                && ViewZsets::toMicros($item->last_activity_at) < $freshestMicros;

            if ((bool) $item->dual_channel === $wantDual && (bool) $item->older_channel_activity === $wantOlder) {
                continue;
            }

            DB::table('ticket_list_items')->where('ticket_id', $item->ticket_id)->update([
                'dual_channel' => $wantDual,
                'older_channel_activity' => $wantOlder,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);

            if ($item->ticket_id !== $row->ticket_id) {
                $changedSiblings[] = $item->ticket_id;
            }
        }

        return $changedSiblings;
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

    /**
     * FR-26's non-mention bell rows. Q7's posture holds row-level too: the
     * payload carries IDENTIFIERS ONLY (event_id, enum statuses, counts) —
     * never reason text or message content. Actors never notify themselves.
     *
     * @param  array<string, mixed>  $payload
     */
    private function mintBellRows(string $eventId, string $name, array $payload): void
    {
        $rows = match ($name) {
            // Assignment → the NEW assignee (a null new_assignee_id IS the
            // unassign — nobody's bell rings for a queue return).
            'ticket.assigned' => ($to = self::strOr($payload, 'new_assignee_id')) !== '' && $to !== self::strOr($payload, 'by')
                ? [[$to, 'notify.inbox.assignment', self::str($payload, 'ticket_id'), []]]
                : [],
            // Escalation-to-ME (the named target) + Doc 06 FR-18's follower
            // notice, minus the actor and the target (no double row).
            'ticket.escalated' => array_merge(
                ($to = self::strOr($payload, 'target_id')) !== '' && $to !== self::strOr($payload, 'actor_id')
                    ? [[$to, 'notify.inbox.escalation', self::str($payload, 'ticket_id'), []]]
                    : [],
                array_map(
                    fn (string $userId): array => [$userId, 'notify.inbox.follower', self::str($payload, 'ticket_id'), ['kind' => 'escalation']],
                    $this->followersExcept(self::str($payload, 'ticket_id'), [self::strOr($payload, 'actor_id'), self::strOr($payload, 'target_id')]),
                ),
            ),
            // Doc 06 FR-18: followers hear status changes.
            'ticket.status_changed' => array_map(
                fn (string $userId): array => [$userId, 'notify.inbox.follower', self::str($payload, 'ticket_id'), [
                    'kind' => 'status', 'old_status' => self::str($payload, 'old_status'), 'new_status' => self::str($payload, 'new_status'),
                ]],
                $this->followersExcept(self::str($payload, 'ticket_id'), [self::strOr($payload, 'actor_id')]),
            ),
            // Doc 06 FR-18: followers hear public replies (never notes —
            // those are the mention row's channel). The author never
            // self-notifies.
            'message.sent' => $this->publicReplyRows($payload),
            // Window closing → the open ticket's assignee (Doc 05 FR-22;
            // the per-category opt-in layer is Doc 16's surface).
            'telegram.window.closing' => ($t = $this->windowClosingTarget($payload)) !== null
                ? [[$t['user_id'], 'notify.inbox.window_closing', $t['ticket_id'], ['expires_at' => self::strOr($payload, 'expires_at')]]]
                : [],
            // Bulk finished → the requester, with the summary COUNTS only.
            'ticket.bulk_completed' => ($to = self::strOr($payload, 'requested_by')) !== ''
                ? [[$to, 'notify.inbox.bulk_completed', null, [
                    'ok' => is_int($payload['ok'] ?? null) ? $payload['ok'] : 0,
                    'failed' => is_array($payload['failed'] ?? null) ? count($payload['failed']) : 0,
                ]]]
                : [],
            default => [],
        };

        foreach ($rows as [$userId, $category, $ticketId, $extra]) {
            DB::table('inbox_notifications')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => self::str($payload, 'tenant_id'),
                'user_id' => $userId,
                'category' => $category,
                'ticket_id' => $ticketId !== null && $ticketId !== '' ? $ticketId : null,
                'conversation_id' => ($c = self::strOr($payload, 'conversation_id')) !== '' ? $c : null,
                'payload' => json_encode(['event_id' => $eventId] + $extra, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /**
     * FR-18's public-reply follower rows: agent-authored, NOT internal, on a
     * conversation with an open ticket — else no fanout.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{0: string, 1: string, 2: string|null, 3: array<string, mixed>}>
     */
    private function publicReplyRows(array $payload): array
    {
        /** @var object{author_type: string, author_id: string|null, is_internal: bool}|null $message */
        $message = DB::table('messages')->where('id', self::str($payload, 'message_id'))
            ->first(['author_type', 'author_id', 'is_internal']);
        if ($message === null || $message->author_type !== 'agent' || $message->is_internal) {
            return [];
        }
        $ticketId = $this->ticketForConversation(self::strOr($payload, 'conversation_id'));
        if ($ticketId === null) {
            return [];
        }

        return array_map(
            fn (string $userId): array => [$userId, 'notify.inbox.follower', $ticketId, ['kind' => 'reply']],
            $this->followersExcept($ticketId, [is_string($message->author_id) ? $message->author_id : '']),
        );
    }

    /**
     * @param  list<string>  $excluded
     * @return list<string>
     */
    private function followersExcept(string $ticketId, array $excluded): array
    {
        if ($ticketId === '') {
            return [];
        }
        /** @var list<string> $followers */
        $followers = DB::table('ticket_followers')->where('ticket_id', $ticketId)->pluck('user_id')->all();

        return array_values(array_diff($followers, array_filter($excluded)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{user_id: string, ticket_id: string}|null
     */
    private function windowClosingTarget(array $payload): ?array
    {
        $ticketId = $this->ticketForConversation(self::strOr($payload, 'conversation_id'));
        if ($ticketId === null) {
            return null;
        }
        /** @var string|null $assignee */
        $assignee = DB::table('tickets')->where('id', $ticketId)->value('assignee_id');

        return is_string($assignee) && $assignee !== '' ? ['user_id' => $assignee, 'ticket_id' => $ticketId] : null;
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
     * R-22 (step 14): converge the projection's tags column from ticket truth
     * in ONE set-based statement — the T-07 historical rewrite touches rows by
     * the hundred thousand, so per-row recompute is the wrong tool. The
     * projection is never the source of truth (C-07); this is it catching up.
     *
     * @return int rows converged
     */
    public function syncTagsFromTruth(): int
    {
        return DB::update(
            <<<'SQL'
                UPDATE ticket_list_items li
                   SET tags = to_jsonb(t.tags)
                  FROM tickets t
                 WHERE li.ticket_id = t.id
                   AND li.tags IS DISTINCT FROM to_jsonb(t.tags)
                SQL,
        );
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
    /**
     * A nullable-tolerant read for fanout keys the catalog allows to be null.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function strOr(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $payload */
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
