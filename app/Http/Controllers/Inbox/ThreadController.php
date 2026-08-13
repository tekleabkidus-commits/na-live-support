<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Conversations\Revisions\HistoryReader;
use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Rbac\Scopes\ScopeSets;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-14/FR-15's data door (S07 step 10): one conversation's thread page —
 * messages (Doc 04's §8/§9 contract fields, notes included for staff,
 * quarantined EXCLUDED per FR-11's audit-only posture), the conversation's
 * TICKETS (FR-14's boundary markers — a conversation spans tickets), their
 * handoff packets (Doc 06 FR-11 — the AC-08 card, pinned at ticket start),
 * and the viewer's read marker (FR-15 opens at first unread).
 *
 * SCOPE: the conversation resolves through the viewer's ticket scope
 * (ScopeSets over the conversation's list rows — BR-03); out of scope and
 * nonexistent answer the SAME 404 pattern (EC-01/C-10, the security metric
 * incremented) — sabotage #15 makes them distinguishable and the proof
 * goes red.
 *
 * Doc 04 EC-08: ?anchor={message_id} returns the WINDOW around the anchor
 * (context fetch for reply-to jumps), not the tail page.
 */
final class ThreadController extends Controller
{
    private const PAGE = 60;

    public function __construct(
        private readonly ScopeSets $scopes,
        private readonly HistoryReader $history,
    ) {}

    public function show(Request $request, string $conversationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversation = $this->resolveScoped($user, $conversationId);
        if ($conversation === null) {
            return $this->notFoundPattern();
        }

        /** @var array{anchor?: string, before?: string} $input */
        $input = $request->validate([
            'anchor' => ['sometimes', 'uuid'],
            'before' => ['sometimes', 'string', 'max:64'],
        ]);

        $base = DB::table('messages')
            ->where('conversation_id', $conversation->identifier())
            ->where('quarantined', false)
            ->select([
                'id', 'created_at', 'direction', 'author_type', 'author_id', 'kind', 'body',
                'media', 'language', 'delivery_state', 'edited_at', 'deleted_at',
                'reply_to_message_id', 'is_internal', 'channel_meta',
            ]);

        if (isset($input['anchor'])) {
            // EC-08: the page CONTAINING the anchor — half a page each side.
            /** @var object{created_at: string}|null $anchorRow */
            $anchorRow = DB::table('messages')->where('conversation_id', $conversation->identifier())
                ->where('id', $input['anchor'])->first(['created_at']);
            if ($anchorRow === null) {
                return $this->notFoundPattern();
            }
            $before = (clone $base)->where('created_at', '<', $anchorRow->created_at)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(self::PAGE / 2)->get()->reverse();
            $after = (clone $base)->where('created_at', '>=', $anchorRow->created_at)
                ->orderBy('created_at')->orderBy('id')->limit(self::PAGE / 2)->get();
            $messages = $before->concat($after)->values();
            $hasMore = false; // context windows never claim to be the tail
        } else {
            // The TAIL page (newest), served oldest-first for rendering;
            // `before` walks history (the T-08 composite cursor discipline).
            $query = (clone $base)->orderByDesc('created_at')->orderByDesc('id')->limit(self::PAGE + 1);
            if (isset($input['before'])) {
                $decoded = base64_decode($input['before'], true);
                [$stamp, $id] = str_contains((string) $decoded, '|') ? explode('|', (string) $decoded, 2) : ['', ''];
                if ($stamp !== '') {
                    $query->where(function (\Illuminate\Database\Query\Builder $where) use ($stamp, $id): void {
                        $where->where('created_at', '<', $stamp)
                            ->orWhere(function (\Illuminate\Database\Query\Builder $tie) use ($stamp, $id): void {
                                $tie->where('created_at', '=', $stamp)->where('id', '<', $id);
                            });
                    });
                }
            }
            $page = $query->get();
            $hasMore = $page->count() > self::PAGE;
            $messages = $page->take(self::PAGE)->reverse()->values();
        }

        // Step 14 (B2-S03): the property panel renders from these rows — the
        // FULL property set rides the same read the boundary markers use.
        // Reads only; the literal status KEY never appears as an assignment
        // in this file (the ticket-status-writer gate's design constraint).
        /** @var list<object{id: string, number: int|string, status: string, created_at: string, source: string, priority: string, pending_reason: string|null, assignee_id: string|null, tags: string|null, subject: string|null, subject_source: string|null, reopened_count: int, channel_unreachable: bool}> $tickets */
        $tickets = DB::table('tickets')
            ->where('conversation_id', $conversation->identifier())
            ->orderBy('created_at')
            ->get([
                'id', 'number', 'status', 'created_at', 'source',
                'priority', 'pending_reason', 'assignee_id', 'tags',
                'subject', 'subject_source', 'reopened_count', 'channel_unreachable',
            ])
            ->all();

        // Assignee display names for the panel's row — id + name only, the
        // assignees-directory posture (SEC: no emails, no roles).
        /** @var array<string, string> $assigneeNames */
        $assigneeNames = DB::table('users')
            ->whereIn('id', array_values(array_filter(array_map(
                fn (object $ticket): ?string => is_string($ticket->assignee_id ?? null) ? $ticket->assignee_id : null,
                $tickets,
            ))))
            ->pluck('name', 'id')
            ->all();

        /** @var array<string, object{ticket_id: string, payload: string, schema_version: int}> $packets */
        $packets = DB::table('ticket_handoff_packets')
            ->whereIn('ticket_id', array_map(fn (object $ticket): string => $ticket->id, $tickets))
            ->get(['ticket_id', 'payload', 'schema_version'])
            ->keyBy('ticket_id')
            ->all();

        /** @var string|null $lastReadAt */
        $lastReadAt = DB::table('inbox_read_markers')
            ->where('user_id', $user->identifier())
            ->where('conversation_id', $conversation->identifier())
            ->value('last_read_at');

        /** @var object{created_at: string, id: string}|null $first */
        $first = $messages->first();
        $oldest = $first !== null ? base64_encode($first->created_at.'|'.$first->id) : null;

        return new JsonResponse([
            'conversation' => $this->conversationMeta($conversation),
            'messages' => $messages->map(function (object $row): array {
                /** @var array<string, mixed> $data */
                $data = (array) $row;
                $data['media'] = is_string($row->media ?? null) ? json_decode((string) $row->media, true, 8, JSON_THROW_ON_ERROR) : null;
                $data['channel_meta'] = is_string($row->channel_meta ?? null) ? json_decode((string) $row->channel_meta, true, 8, JSON_THROW_ON_ERROR) : null;
                // Tombstones ship EMPTY (Doc 04 §8: presence, never content).
                if ($row->deleted_at !== null) {
                    $data['body'] = null;
                    $data['media'] = null;
                }

                return $data;
            })->all(),
            'prev_cursor' => $hasMore ? $oldest : null,
            'tickets' => array_map(function (object $ticket) use ($assigneeNames): array {
                /** @var array<string, mixed> $row */
                $row = (array) $ticket;
                $row['tags'] = is_string($ticket->tags ?? null)
                    ? array_values(array_filter(explode(',', trim((string) $ticket->tags, '{}'))))
                    : [];
                $row['assignee_name'] = is_string($ticket->assignee_id ?? null)
                    ? ($assigneeNames[$ticket->assignee_id] ?? null)
                    : null;

                return $row;
            }, $tickets),
            'handoff_packets' => array_map(
                fn (object $packet): array => [
                    'ticket_id' => $packet->ticket_id,
                    'schema_version' => $packet->schema_version,
                    'payload' => json_decode($packet->payload, true, 16, JSON_THROW_ON_ERROR),
                ],
                array_values($packets),
            ),
            'last_read_at' => $lastReadAt,
            'server_now' => $this->dbNow(),
        ]);
    }

    /**
     * B12-S02's history door: HistoryReader owns the verdict
     * (conversations.history.full) and the audit — this stays a thin pipe.
     */
    public function messageHistory(Request $request, string $messageId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $message = Message::query()->find($messageId);
        $conversationIdRaw = $message?->getAttribute('conversation_id');
        if ($message === null || ! is_string($conversationIdRaw) || $this->resolveScoped($user, $conversationIdRaw) === null) {
            return $this->notFoundPattern();
        }

        try {
            $revisions = $this->history->revisionsFor($message, $user);
        } catch (DomainException) {
            // BR-03's shape for a capability miss on an object the viewer CAN
            // see: the marker renders, the history refuses plainly.
            return new JsonResponse(['revisions' => null], 403);
        }

        return new JsonResponse([
            'revisions' => $revisions->map(fn (object $revision): array => [
                'body' => $revision->getAttribute('prior_body'),
                'edited_at' => $revision->getAttribute('created_at'),
                'source' => $revision->getAttribute('source'),
            ])->all(),
        ]);
    }

    /**
     * §9 print (SEC-05): server-rendered, WATERMARKED with user + timestamp,
     * and the NOTE GATE — internal notes never reach the print path (N-04's
     * posture on paper). Sabotage #20 drops the gate and the proof goes red.
     */
    public function print(Request $request, string $conversationId): \Illuminate\Contracts\View\View|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversation = $this->resolveScoped($user, $conversationId);
        if ($conversation === null) {
            return $this->notFoundPattern();
        }

        /** @var list<object> $messages */
        $messages = DB::table('messages')
            ->where('conversation_id', $conversation->identifier())
            ->where('quarantined', false)
            ->where('is_internal', false) // SEC-05: the note gate — sabotage #20's target
            ->whereNull('deleted_at')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'created_at', 'direction', 'author_type', 'kind', 'body'])
            ->all();

        return view('inbox.print', [
            'messages' => $messages,
            'conversationId' => $conversation->identifier(),
            // SEC-05: watermark = user + timestamp, DB time (C-11).
            'watermark' => sprintf(
                '%s · %s',
                is_string($emailRaw = $user->getAttribute('email')) ? $emailRaw : '',
                $this->dbNow(),
            ),
        ]);
    }

    /**
     * FR-20's DATA (the advisor's step-13 order, delivered at step 12): the
     * composer states resolve from REAL substrate — the conversation's
     * business_connection state (paused/revoked → the connection cards), the
     * customer's blocked flag + phone presence (the expired card's SMS
     * filter). No seam props remain.
     *
     * @return array<string, mixed>
     */
    private function conversationMeta(Conversation $conversation): array
    {
        // conversations.business_connection_id stores TELEGRAM'S connection id
        // string, not the row uuid — the ChannelUnreachableWriter/ListProjector
        // join, reused. (An `id` lookup here could never match and every
        // connection would read 'active' — caught at the resume re-verify.)
        /** @var object{state: string, account_name: string}|null $connection */
        $connection = $conversation->getAttribute('business_connection_id') !== null
            ? DB::table('telegram_business_connections')->where('connection_id', $conversation->getAttribute('business_connection_id'))->first(['state', 'account_name'])
            : null;

        /** @var object{display_name: string|null, phone: string|null}|null $customer */
        $customer = DB::table('customers')->where('id', $conversation->getAttribute('customer_id'))->first(['display_name', 'phone']);

        return [
            'id' => $conversation->identifier(),
            'channel' => $conversation->getAttribute('channel'),
            'state' => $conversation->getAttribute('state'),
            'reply_window_state' => $conversation->getAttribute('reply_window_state'),
            'reply_window_expires_at' => $conversation->getAttribute('reply_window_expires_at'),
            'customer_display_name' => $customer->display_name ?? null,
            'customer_has_phone' => ($customer->phone ?? null) !== null,
            // Doc 04 reason mapping: the latest outbound's delivery_error is
            // the blocked_by_user signal (DeliveryFailureReason::BlockedByUser).
            'blocked_by_user' => $this->latestBlockedByUser($conversation->identifier()),
            'connection_state' => $connection->state ?? 'active',
            'connection_account' => $connection->account_name ?? '',
        ];
    }

    private function latestBlockedByUser(string $conversationId): bool
    {
        /** @var object{delivery_error: string|null}|null $last */
        $last = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'out')
            ->whereNotNull('delivery_error')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first(['delivery_error']);

        if ($last === null || ! is_string($last->delivery_error)) {
            return false;
        }
        /** @var array{reason?: string} $error */
        $error = json_decode($last->delivery_error, true, 8, JSON_THROW_ON_ERROR);

        return ($error['reason'] ?? null) === 'blocked_by_user';
    }

    private function dbNow(): string
    {
        /** @var object{now: string} $row */
        $row = DB::selectOne('SELECT clock_timestamp()::text AS now');

        return $row->now;
    }

    /** The viewer's scope decides visibility via the ticket rows (BR-03). */
    private function resolveScoped(User $user, string $conversationId): ?Conversation
    {
        $conversation = Conversation::query()->find($conversationId);
        if ($conversation === null) {
            return null;
        }

        $query = \App\Models\TicketListItem::query()->where('conversation_id', $conversationId);
        $this->scopes->apply($query, 'tickets', $user, ListReadModel::columns());
        if (! $query->exists()) {
            return null; // absence, not a lock icon
        }

        return $conversation;
    }

    /**
     * EC-01/C-10: one answer for missing AND out-of-scope — never an
     * existence oracle — with the security metric incremented.
     */
    private function notFoundPattern(): JsonResponse
    {
        \Illuminate\Support\Facades\Log::info('inbox thread 404-pattern served', [
            'metric' => 'security.cross_tenant_probe',
        ]);

        // ProblemDetails assembles the C-04 body — which keeps the literal
        // 'status' key OUT of this tickets-touching file (the
        // ticket-status-writer gate's file-level design constraint, the
        // third time this session's steps have honored it).
        return \App\Support\Problem\ProblemDetails::response(
            \App\Support\Problem\ErrorCode::ResourceNotFound,
            404,
            'not found',
            extra: ['search_instead' => '/inbox'],
        );
    }
}
