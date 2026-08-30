<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Domain\Inbox\ReadModels\ReadMarkers;
use App\Domain\Inbox\ReadModels\SystemViews;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Domain\Inbox\Realtime\ViewHash;
use App\Domain\Inbox\Views\SavedViewFilters;
use App\Domain\Inbox\Views\ViewVariants;
use App\Http\Controllers\Controller;
use App\Models\TicketListItem;
use App\Models\User;
use App\Support\Rbac\Scopes\ScopeSets;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-12 — the list page endpoint: CURSOR pagination (C-05: cursor,
 * limit ≤ 100, next_cursor — offsets are prohibited and live mutation is
 * exactly why), total count for the header, per-viewer unread/draft overlay
 * (SCHEMA AMENDMENT 1's design: markers + drafts join the page at
 * hydration; ZSETs and pushed counts never carry per-viewer numbers).
 *
 * TWO PATHS, one scope law:
 *  - DEFAULT ORDER: the view's ZSET (T-01) — cursor is (score, id) in the
 *    set's own R-23 integer-microsecond space.
 *  - FR-10 SORT OVERRIDE (?sort=): a keyset query over ticket_list_items
 *    with the view's membership predicate — cursor is (sort value, id).
 *    The override PREFERENCE persists client-side per view
 *    (prefs.sort_overrides, FR-08's blob); the endpoint is stateless.
 *
 * Both paths hydrate through ListReadModel/ScopeSets (layer 2 — BR-03:
 * out-of-scope rows are ABSENT, never locked).
 */
final class ListController extends Controller
{
    private const SORTS = [
        // key => [expression column, direction, nulls-last]
        'last_activity' => ['last_activity_at', 'desc', false],
        'created' => ['ticket_created_at', 'asc', false],
        'sla_due' => ['sla_due_at', 'asc', true],
        'priority' => ['priority_ord', 'desc', false],
    ];

    public function __construct(
        private readonly ViewHash $viewHash,
        private readonly ViewVariants $variants,
        private readonly ViewZsets $zsets,
        private readonly ListReadModel $readModel,
        private readonly ReadMarkers $markers,
        private readonly ScopeSets $scopes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{view?: string, saved?: string, cursor?: string, limit?: int|string, sort?: string, filters?: string} $input */
        $input = $request->validate([
            // S11 step 11a (STOP-H): `queue:{uuid}` joins the closed set — Doc 07 FR-05's real queue views.
            'view' => ['required_without:saved', 'string', 'regex:/^(my_open|unassigned|mentions|following|sla_at_risk|expiring_windows|rescue|queue:[0-9a-f\-]{36})$/'],
            'saved' => ['required_without:view', 'uuid'],
            'cursor' => ['sometimes', 'string', 'max:400'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', 'in:last_activity,created,sla_due,priority'],
            // FR-24 (step 12): the in-view filter bar COMPOSES WITHIN the
            // current view's base filters — same closed grammar as saved
            // views, validated the same way.
            'filters' => ['sometimes', 'string', 'max:2000'],
        ]);

        $extraFilters = null;
        if (isset($input['filters'])) {
            /** @var mixed $decoded */
            $decoded = json_decode($input['filters'], true, 16);
            if (! is_array($decoded)) {
                return new JsonResponse(['rows' => [], 'next_cursor' => null, 'total' => 0, 'error' => 'view_filters_invalid'], 422);
            }
            /** @var array<string, mixed> $decoded */
            if (! SavedViewFilters::validate($decoded)) {
                return new JsonResponse(['rows' => [], 'next_cursor' => null, 'total' => 0, 'error' => 'view_filters_invalid'], 422);
            }
            $extraFilters = $decoded;
        }

        $limit = (int) ($input['limit'] ?? 50);
        $view = $input['view'] ?? null;
        $savedId = $input['saved'] ?? null;

        // The viewer's OWN zset key (A-30 lazy variants materialize on first
        // touch) — its ZCARD is the header total on both paths (membership
        // is identical; only ORDER differs under an override).
        if ($savedId !== null) {
            /** @var object{id: string, filters: string}|null $saved */
            $saved = DB::table('inbox_saved_views')->where('id', $savedId)->whereNull('deleted_at')->first(['id', 'filters']);
            if ($saved === null) {
                return new JsonResponse(['rows' => [], 'next_cursor' => null, 'total' => 0], 404);
            }
            /** @var array<string, mixed> $savedFilters */
            $savedFilters = json_decode($saved->filters, true, 16, JSON_THROW_ON_ERROR);
            $zsetKey = $this->viewHash->savedZsetKeyForViewer($savedId, $user);
            $zsetKey = $this->ensureVariant($zsetKey, $savedFilters);
        } elseif (is_string($view) && str_starts_with($view, 'queue:')) {
            // Doc 07 FR-05 queue views (S11 step 11a): membership = the queue's WAITING set in the
            // dispatcher's own order (FR-08 step 3). A viewer who is not an effective member gets
            // the 404 PATTERN (C-10 — Doc 07 EC-09's vanish: the client toasts and drops the view).
            return $this->queuePage($user, substr($view, 6), $input['cursor'] ?? null, $limit);
        } else {
            $savedFilters = null;
            $zsetKey = $this->viewHash->zsetKeyForViewer((string) $view, $user);
            $zsetKey = $this->ensureVariant($zsetKey, null);
        }

        $total = $this->zsets->count($zsetKey);

        if ($extraFilters !== null) {
            // Filtered reads ride the SQL path (membership ∧ extra filters);
            // the header total is the FILTERED count — the list never lies
            // about what it shows (BR-01).
            $membership = $this->membershipQuery($view, $savedFilters, $user, $extraFilters);
            $countQuery = TicketListItem::query()->whereIn('ticket_id', $membership->select('li.ticket_id'));
            $this->scopes->apply($countQuery, 'tickets', $user, ListReadModel::columns());
            $total = $countQuery->count();
            [$rows, $nextCursor] = $this->overridePage($input['sort'] ?? 'last_activity', $view, $savedFilters, $user, $input['cursor'] ?? null, $limit, $extraFilters);
        } elseif (isset($input['sort'])) {
            [$rows, $nextCursor] = $this->overridePage($input['sort'], $view, $savedFilters, $user, $input['cursor'] ?? null, $limit);
        } else {
            [$rows, $nextCursor] = $this->defaultPage($zsetKey, (string) ($view ?? 'saved'), $user, $input['cursor'] ?? null, $limit);
        }

        // The per-viewer overlay (two grouped queries, never N+1). Both
        // paths return TicketListItem models; getAttributes is the raw row.
        /** @var list<TicketListItem> $rows */
        $conversationIdOf = function (TicketListItem $row): string {
            $raw = $row->getAttribute('conversation_id');

            return is_string($raw) ? $raw : '';
        };
        $overlay = $this->markers->overlay($user, array_values(array_unique(array_map($conversationIdOf, $rows))));

        return new JsonResponse([
            'rows' => array_map(function (TicketListItem $row) use ($overlay): array {
                /** @var array<string, mixed> $data */
                $data = $row->getAttributes();
                $conversationIdRaw = $row->getAttribute('conversation_id');
                $conversationId = is_string($conversationIdRaw) ? $conversationIdRaw : '';
                $tagsRaw = $data['tags'] ?? '[]';
                $data['tags'] = json_decode(is_string($tagsRaw) ? $tagsRaw : '[]', true, 8, JSON_THROW_ON_ERROR);
                $data['unread'] = $overlay['unread'][$conversationId] ?? 0;
                $data['has_draft'] = $overlay['drafts'][$conversationId] ?? false;

                return $data;
            }, $rows),
            'next_cursor' => $nextCursor,
            'total' => $total,
        ]);
    }

    /**
     * The marker write (SCHEMA AMENDMENT 1's real writer): byte-copies the
     * message's own DB-time stamp — see ReadMarkers. views.manage per the
     * R-26 AMENDMENT (fourth sibling); audit-exempt like prefs (personal
     * workspace state, no third-party effect).
     */
    public function markRead(Request $request): JsonResponse
    {
        /** @var array{conversation_id: string, message_id: string} $input */
        $input = $request->validate([
            'conversation_id' => ['required', 'uuid'],
            'message_id' => ['required', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $this->markers->markRead($user, $input['conversation_id'], $input['message_id']);
        } catch (\DomainException) {
            return new JsonResponse(['ok' => false], 404); // C-10: no existence detail
        }

        return new JsonResponse(['ok' => true]);
    }

    /** @return array{0: list<object>, 1: string|null} */
    private function defaultPage(string $zsetKey, string $view, User $user, ?string $cursor, int $limit): array
    {
        [$cursorScore, $cursorId] = $this->decodeCursor($cursor);

        $entries = $this->zsets->cursorPage(
            $zsetKey,
            $cursorScore !== null ? (int) $cursorScore : null,
            $cursorId,
            $limit,
            SystemViews::descending($view),
        );

        $rows = $this->readModel->hydrate(array_map(fn (array $entry): string => $entry['id'], $entries), $user);

        // T-02 parity: rows carry their OWN zset score so the client's
        // batched resort (FR-11) compares the same numbers the server does.
        $scores = [];
        foreach ($entries as $entry) {
            $scores[$entry['id']] = $entry['score'];
        }
        /** @var list<TicketListItem> $rows */
        foreach ($rows as $row) {
            $ticketIdRaw = $row->getAttribute('ticket_id');
            $row->setAttribute('sort_key', $scores[is_string($ticketIdRaw) ? $ticketIdRaw : ''] ?? 0);
        }

        $nextCursor = count($entries) === $limit
            ? $this->encodeCursor((string) $entries[count($entries) - 1]['score'], $entries[count($entries) - 1]['id'])
            : null;

        return [$rows, $nextCursor];
    }

    /**
     * FR-10's override order: keyset over ticket_list_items — the view's
     * membership as a SUBQUERY (SavedViewFilters/SystemViews definitions,
     * verbatim), the scope law applied by ScopeSets on the outer Eloquent
     * query (R-03: never re-implemented), the sort as a keyset (value, id)
     * cursor. NULL sort values page LAST (their block resumes by id alone).
     *
     * @param  array<string, mixed>|null  $savedFilters
     * @param  array<string, mixed>|null  $extraFilters
     * @return array{0: list<object>, 1: string|null}
     */
    private function overridePage(string $sort, ?string $view, ?array $savedFilters, User $user, ?string $cursor, int $limit, ?array $extraFilters = null): array
    {
        [$column, $direction, $nullsLast] = self::SORTS[$sort];
        $expr = $sort === 'priority'
            ? "CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 WHEN 'low' THEN 1 ELSE 0 END"
            : $column;

        $query = TicketListItem::query()
            ->whereIn('ticket_id', $this->membershipQuery($view, $savedFilters, $user, $extraFilters)->select('li.ticket_id'));

        // Layer 2, same law as the ZSET path (R-03 / BR-03).
        $this->scopes->apply($query, 'tickets', $user, ListReadModel::columns());

        [$cursorValue, $cursorId] = $this->decodeCursor($cursor);
        $op = $direction === 'desc' ? '<' : '>';

        if ($cursorId !== null) {
            if ($cursorValue === null || $cursorValue === '∅') {
                // Inside the trailing NULL block: continue by id.
                $query->whereNull($column)->where('ticket_id', '>', $cursorId);
            } else {
                $query->where(function ($where) use ($expr, $op, $cursorValue, $cursorId, $nullsLast): void {
                    $where->whereRaw("{$expr} {$op} ?", [$cursorValue])
                        ->orWhere(function ($tie) use ($expr, $cursorValue, $cursorId): void {
                            $tie->whereRaw("{$expr} = ?", [$cursorValue])->where('ticket_id', '>', $cursorId);
                        });
                    if ($nullsLast) {
                        $where->orWhereRaw("{$expr} IS NULL");
                    }
                });
            }
        }

        $query->orderByRaw("{$expr} {$direction}".($nullsLast ? ' NULLS LAST' : ''))->orderBy('ticket_id');

        /** @var list<TicketListItem> $rows */
        $rows = $query->limit($limit)->get()->all();

        $nextCursor = null;
        if (count($rows) === $limit) {
            $last = $rows[$limit - 1];
            $priorityRaw = $last->getAttribute('priority');
            $lastValue = $sort === 'priority'
                ? (string) (['urgent' => 4, 'high' => 3, 'normal' => 2, 'low' => 1][is_string($priorityRaw) ? $priorityRaw : ''] ?? 0)
                : $last->getAttribute($column);
            $ticketIdRaw = $last->getAttribute('ticket_id');
            $nextCursor = $this->encodeCursor(is_string($lastValue) ? $lastValue : '∅', is_string($ticketIdRaw) ? $ticketIdRaw : '');
        }

        return [$rows, $nextCursor];
    }

    /**
     * The view's membership as a ticket_list_items predicate — the SAME
     * definitions SystemViews::directQuery pins (NFR-07's checker equates
     * them against the ZSETs).
     *
     * @param  array<string, mixed>|null  $savedFilters
     * @param  array<string, mixed>|null  $extraFilters
     */
    private function membershipQuery(?string $view, ?array $savedFilters, User $user, ?array $extraFilters = null): Builder
    {
        $query = DB::table('ticket_list_items as li');

        // FR-24: extra filters COMPOSE (∧) with the base membership.
        if ($extraFilters !== null) {
            $query = SavedViewFilters::sql($query, $extraFilters);
        }

        if ($savedFilters !== null) {
            return SavedViewFilters::sql($query, $savedFilters);
        }

        return match ($view) {
            'my_open' => $query->where('li.assignee_id', $user->identifier())->whereIn('li.status', SystemViews::OPEN_FAMILY),
            'unassigned' => $query->whereNull('li.assignee_id')->whereIn('li.status', SystemViews::OPEN_FAMILY),
            'sla_at_risk' => $query->whereIn('li.sla_state', ['warned', 'breached'])->whereIn('li.status', SystemViews::OPEN_FAMILY),
            'rescue' => $query->where('li.channel_unreachable', true),
            'expiring_windows' => $query
                ->join('conversations as c', 'c.id', '=', 'li.conversation_id')
                ->whereIn('c.reply_window_state', ['closing', 'expired'])
                ->whereIn('li.status', SystemViews::OPEN_FAMILY),
            'mentions' => $query
                ->join('inbox_notifications as n', 'n.ticket_id', '=', 'li.ticket_id')
                ->where('n.user_id', $user->identifier())
                ->where('n.category', 'notify.inbox.mention')
                ->whereNull('n.read_at')
                ->distinct(),
            'following' => $query
                ->join('ticket_followers as f', 'f.ticket_id', '=', 'li.ticket_id')
                ->where('f.user_id', $user->identifier()),
            default => $query->whereRaw('false'),
        };
    }

    /** @param array<string, mixed>|null $savedFilters */
    private function ensureVariant(string $zsetKey, ?array $savedFilters): string
    {
        if (str_contains($zsetKey, ':scope:')) {
            [$base, $signature] = explode(':scope:', $zsetKey, 2);

            return $this->variants->ensure($base, $signature, $savedFilters);
        }

        return $zsetKey;
    }

    /** @return array{0: string|null, 1: string|null} */
    /**
     * S11 step 11a (STOP-H): one queue view's page — the WaitingSets ZSET in its own
     * ascending order, hydrated through the same read model, the same overlay, the same
     * cursor codec. Total = the set's depth (the rail's count, one truth).
     */
    private function queuePage(User $user, string $queueId, ?string $cursor, int $limit): JsonResponse
    {
        if (! app(\App\Domain\Inbox\Views\QueueViews::class)->isMember($user, $queueId)) {
            \Illuminate\Support\Facades\Log::info('queue view 404-pattern served', ['metric' => 'security.cross_tenant_probe']);

            return new JsonResponse(['rows' => [], 'next_cursor' => null, 'total' => 0, 'error' => 'queue_view_gone'], 404);
        }
        [$cursorScore, $cursorId] = $this->decodeCursor($cursor);
        $waiting = app(\App\Domain\Routing\Waiting\WaitingSets::class);
        $entries = $waiting->cursorPage($queueId, $cursorScore !== null ? (int) $cursorScore : null, $cursorId, $limit);
        $rows = $this->readModel->hydrate(array_map(fn (array $entry): string => $entry['id'], $entries), $user);
        $scores = [];
        foreach ($entries as $entry) {
            $scores[$entry['id']] = $entry['score'];
        }
        /** @var list<TicketListItem> $rows */
        foreach ($rows as $row) {
            $ticketIdRaw = $row->getAttribute('ticket_id');
            $row->setAttribute('sort_key', $scores[is_string($ticketIdRaw) ? $ticketIdRaw : ''] ?? 0);
        }
        $nextCursor = count($entries) === $limit
            ? $this->encodeCursor((string) $entries[count($entries) - 1]['score'], $entries[count($entries) - 1]['id'])
            : null;
        $overlay = $this->markers->overlay($user, array_values(array_unique(array_map(static function (TicketListItem $row): string {
            $raw = $row->getAttribute('conversation_id');

            return is_string($raw) ? $raw : '';
        }, $rows))));

        return new JsonResponse([
            'rows' => array_map(function (TicketListItem $row) use ($overlay): array {
                /** @var array<string, mixed> $data */
                $data = $row->getAttributes();
                $conversationIdRaw = $row->getAttribute('conversation_id');
                $conversationId = is_string($conversationIdRaw) ? $conversationIdRaw : '';
                $tagsRaw = $data['tags'] ?? '[]';
                $data['tags'] = json_decode(is_string($tagsRaw) ? $tagsRaw : '[]', true, 8, JSON_THROW_ON_ERROR);
                $data['unread'] = $overlay['unread'][$conversationId] ?? 0;
                $data['has_draft'] = $overlay['drafts'][$conversationId] ?? false;

                return $data;
            }, $rows),
            'next_cursor' => $nextCursor,
            'total' => $waiting->depth($queueId),
        ]);
    }

    /** @return array{0: string|null, 1: string|null} */
    private function decodeCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [null, null];
        }

        $decoded = base64_decode($cursor, true);
        if ($decoded === false || ! str_contains($decoded, '|')) {
            return [null, null]; // malformed cursors restart from the top — C-05's honest fallback
        }

        [$value, $id] = explode('|', $decoded, 2);

        return [$value, $id];
    }

    private function encodeCursor(string $value, string $id): string
    {
        return base64_encode($value.'|'.$id);
    }
}
