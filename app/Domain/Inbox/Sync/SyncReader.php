<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Sync;

use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Models\TicketListItem;
use App\Models\User;
use App\Support\Rbac\Scopes\ScopeSets;
use Illuminate\Support\Facades\DB;

/**
 * T-07's single reconciliation path (S07 step 5): ordered events since
 * cursor, for the caller's subscribed scopes, bounded, watermarked.
 *
 * ── THE A-33/B-4 CONTRACT, discharged here ──────────────────────────────
 *
 * ORDERING KEY: (occurred_at, id) — occurred_at is the A-33 byte-copy of the
 * publish transaction's DB stamp; id is UUIDv7 and is the WITHIN-STAMP
 * TIEBREAK ONLY (R-19's narrowing: UUIDv7 is never a primary cross-server
 * ordering source; same-transaction events share a stamp and the id orders
 * them totally within it).
 *
 * THE WATERMARK: only events stamped ≤ DB-now − WATERMARK_SECONDS are
 * served, and next_cursor never advances past the last SERVED event.
 * DERIVATION (the commit-skew bound it protects against): an event becomes
 * READABLE at relay time but is STAMPED at publish-transaction start, so a
 * long publish transaction or relay lag lands rows BEHIND stamps a naive
 * cursor already passed. W = 10 s ≥ the §12 relay-lag budget (≤ 2 s p95,
 * Doc 01) + the platform's longest expected write transaction (lock_timeout
 * 5 s bounds lock waits; request budgets are sub-second) with margin.
 * RESIDUAL, stated: a publish transaction held open longer than W that
 * commits after a cursor passed its stamp CAN be skipped — that shape sits
 * beyond the relay-lag ALERT (60 s) and is an operational incident whose
 * recovery is the overflow/full-refetch instruction plus NFR-07's checker;
 * it is not silently absorbed.
 *
 * FRESHNESS TRADE-OFF (the endpoint contract): reconnect sync serves
 * SETTLED events — at least W seconds old. The LIVE path is Reverb's
 * (T-02 deltas + T-03 counts); sync exists to close gaps, not to race the
 * socket.
 *
 * SCOPE (A-31): ticket/message events are served only when their subject is
 * visible through the SAME ScopeSets predicate the lists hydrate with
 * (joined via ticket_list_items) — reconnect delivery equals list/thread
 * visibility, proven by the golden's scope-excluded case. Tenant-wide
 * workspace events (channel health, connection state) pass untouched.
 *
 * SABOTAGE #10's TARGET: break the ordering (or drop the watermark) and the
 * reconnect goldens go red.
 */
final class SyncReader
{
    public const WATERMARK_SECONDS = 10;

    public const MAX_EVENTS = 5_000;

    /** Ticket/message-shaped events that scope-filter via their subject row. */
    private const SUBJECT_KEYS = ['ticket_id', 'source_ticket_id', 'origin_ticket_id', 'conversation_id'];

    public function __construct(
        private readonly ScopeSets $scopes,
        private readonly int $watermarkSeconds = self::WATERMARK_SECONDS,
        private readonly int $maxEvents = self::MAX_EVENTS,
    ) {}

    /**
     * @return array{events: list<array<string, mixed>>, next_cursor: string|null, overflow: bool, server_now: string}
     */
    public function since(?string $cursor, User $viewer): array
    {
        [$cursorStamp, $cursorId] = $this->decode($cursor);

        /** @var object{now: string, boundary: string} $clock */
        $clock = DB::selectOne(sprintf(
            "SELECT clock_timestamp() AS now, (clock_timestamp() - interval '%d seconds') AS boundary",
            $this->watermarkSeconds,
        ));

        $query = DB::table('events')
            ->where('occurred_at', '<=', $clock->boundary)
            ->when($cursorStamp !== null, function ($q) use ($cursorStamp, $cursorId): void {
                $q->whereRaw('(occurred_at, id) > (?, ?)', [$cursorStamp, $cursorId]);
            })
            ->orderBy('occurred_at')->orderBy('id')
            ->limit($this->maxEvents + 1);

        /** @var list<object{id: string, name: string, schema_version: int, payload: string, occurred_at: string}> $rows */
        $rows = $query->get(['id', 'name', 'schema_version', 'payload', 'occurred_at'])->all();

        $overflow = count($rows) > $this->maxEvents;
        if ($overflow) {
            // T-07: overflow instructs a FULL REFETCH of affected views — the
            // client abandons deltas and reloads; no partial page pretends to
            // be a complete replay.
            return [
                'events' => [],
                'next_cursor' => null,
                'overflow' => true,
                'server_now' => $clock->now,
            ];
        }

        $visible = $this->scopeFilter($rows, $viewer);

        $last = $visible === [] ? null : $visible[count($visible) - 1];

        return [
            'events' => array_map(static fn (object $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'schema_version' => $row->schema_version,
                'payload' => json_decode($row->payload, true, 64, JSON_THROW_ON_ERROR),
                'occurred_at' => $row->occurred_at,
            ], $visible),
            // The cursor advances over everything EXAMINED (scope-dropped
            // rows included — they will never become visible to this viewer
            // retroactively; a scope CHANGE forces the resubscribe/refetch
            // path, SEC-01), but never past the watermark boundary.
            'next_cursor' => $rows === [] ? $cursor : $this->encode(end($rows)->occurred_at, end($rows)->id),
            'overflow' => false,
            'server_now' => $clock->now,
        ];
    }

    /**
     * A-31: subject-scoped events pass only when the viewer's list scope
     * admits their row; unknown subjects (no list row yet) are DROPPED for
     * subject-shaped events — the projector will row them and the next sync
     * serves their later events; tenant-wide events pass.
     *
     * @param  list<object{id: string, name: string, schema_version: int, payload: string, occurred_at: string}>  $rows
     * @return list<object{id: string, name: string, schema_version: int, payload: string, occurred_at: string}>
     */
    private function scopeFilter(array $rows, User $viewer): array
    {
        $subjects = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, 64, JSON_THROW_ON_ERROR);
            foreach (self::SUBJECT_KEYS as $key) {
                if (isset($payload[$key]) && is_string($payload[$key])) {
                    $subjects[$row->id] = ['key' => $key, 'value' => $payload[$key]];
                    break;
                }
            }
        }

        if ($subjects === []) {
            return $rows;
        }

        $ticketIds = [];
        $conversationIds = [];
        foreach ($subjects as $subject) {
            $subject['key'] === 'conversation_id'
                ? $conversationIds[] = $subject['value']
                : $ticketIds[] = $subject['value'];
        }

        // The SAME predicate the lists hydrate with (A-31's condition).
        $visibleQuery = TicketListItem::query()->where(function ($q) use ($ticketIds, $conversationIds): void {
            $q->whereIn('ticket_id', array_unique($ticketIds));
            if ($conversationIds !== []) {
                $q->orWhereIn('conversation_id', array_unique($conversationIds));
            }
        });
        $this->scopes->apply($visibleQuery, 'tickets', $viewer, ListReadModel::columns());

        /** @var list<object{ticket_id: string, conversation_id: string}> $visibleRows */
        $visibleRows = $visibleQuery->get(['ticket_id', 'conversation_id'])->all();
        $visibleTickets = array_flip(array_column($visibleRows, 'ticket_id'));
        $visibleConversations = array_flip(array_column($visibleRows, 'conversation_id'));

        return array_values(array_filter($rows, function (object $row) use ($subjects, $visibleTickets, $visibleConversations): bool {
            if (! isset($subjects[$row->id])) {
                return true; // tenant-wide workspace event
            }
            $subject = $subjects[$row->id];

            return $subject['key'] === 'conversation_id'
                ? isset($visibleConversations[$subject['value']])
                : isset($visibleTickets[$subject['value']]);
        }));
    }

    /** @return array{0: string|null, 1: string} */
    private function decode(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [null, ''];
        }

        $decoded = base64_decode($cursor, true);
        if ($decoded === false || ! str_contains($decoded, '|')) {
            return [null, ''];
        }

        [$stamp, $id] = explode('|', $decoded, 2);

        return [$stamp, $id];
    }

    private function encode(string $stamp, string $id): string
    {
        return base64_encode($stamp.'|'.$id);
    }
}
