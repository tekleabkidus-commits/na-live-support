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
        /** @var list<object{id: string, number: int|string, status: string, created_at: string, source: string, priority: string, pending_reason: string|null, assignee_id: string|null, tags: string|null, subject: string|null, subject_source: string|null, reopened_count: int, channel_unreachable: bool, department_id: string|null, merged_into: string|null, origin_ticket_id: string|null, auto_close_at: string|null, closed_at: string|null, resolved_at: string|null, spam_marked_at: string|null, spam_marked_by: string|null, escalation: string|null, previous_ticket_id: string|null}> $tickets */
        $tickets = DB::table('tickets')
            ->where('conversation_id', $conversation->identifier())
            ->orderBy('created_at')
            ->get([
                'id', 'number', 'status', 'created_at', 'source',
                'priority', 'pending_reason', 'assignee_id', 'tags',
                'subject', 'subject_source', 'reopened_count', 'channel_unreachable',
                'department_id', 'merged_into', 'origin_ticket_id',
                // B2-S04 (step 14): the state banners render from these —
                // C8's auto-close countdown, C9's reopen window boundary,
                // C10's spam window + marker, C11's escalation record, C13's
                // earlier-ticket link. Reads only, same as everything here.
                'auto_close_at', 'closed_at', 'resolved_at',
                'spam_marked_at', 'spam_marked_by', 'escalation',
                'previous_ticket_id',
            ])
            ->all();

        // Display names for the panel's rows and the state banners — id +
        // name only, the assignees-directory posture (SEC: no emails, no
        // roles). One lookup covers assignees, spam markers, and the
        // escalation record's actor/target.
        $nameIds = [];
        foreach ($tickets as $ticketRow) {
            $nameIds[] = is_string($ticketRow->assignee_id ?? null) ? $ticketRow->assignee_id : null;
            $nameIds[] = is_string($ticketRow->spam_marked_by ?? null) ? $ticketRow->spam_marked_by : null;
            if (is_string($ticketRow->escalation ?? null)) {
                /** @var array{by?: string|null, target?: string|null} $escalation */
                $escalation = (array) json_decode((string) $ticketRow->escalation, true, 4);
                $nameIds[] = is_string($escalation['by'] ?? null) ? $escalation['by'] : null;
                $nameIds[] = is_string($escalation['target'] ?? null) ? $escalation['target'] : null;
            }
        }
        /** @var array<string, string> $assigneeNames */
        $assigneeNames = DB::table('users')
            ->whereIn('id', array_values(array_filter($nameIds)))
            ->pluck('name', 'id')
            ->all();

        // C9's boundary, the database's own arithmetic (C-11) — the client
        // renders the countdown, it never derives the window.
        $reopenDays = app(\App\Support\Settings\SettingsRepository::class)->get('tickets.reopen.days');
        $reopenDays = is_int($reopenDays) ? $reopenDays : 7;

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
            'tickets' => array_map(function (object $ticket) use ($assigneeNames, $reopenDays): array {
                /** @var array<string, mixed> $row */
                $row = (array) $ticket;
                $row['tags'] = is_string($ticket->tags ?? null)
                    ? array_values(array_filter(explode(',', trim((string) $ticket->tags, '{}'))))
                    : [];
                $row['assignee_name'] = is_string($ticket->assignee_id ?? null)
                    ? ($assigneeNames[$ticket->assignee_id] ?? null)
                    : null;
                // B13: the "Copilot draft, edited by staff" label needs the
                // PROVENANCE the column alone cannot carry — the relayed log
                // knows whether an AI subject ever existed on this ticket.
                // The panel's SLA/window chips read the projection's own
                // columns — one truth, no recompute here.
                /** @var object{sla_state: string|null, sla_due_at: string|null, window_expires_at: string|null}|null $item */
                $item = DB::table('ticket_list_items')->where('ticket_id', $ticket->id)
                    ->first(['sla_state', 'sla_due_at', 'window_expires_at']);
                $row['sla_state'] = $item->sla_state ?? null;
                $row['sla_due_at'] = $item->sla_due_at ?? null;
                $row['window_expires_at'] = $item->window_expires_at ?? null;
                // Linked tickets (merge/split family), numbers resolved.
                $row['merged_into_number'] = is_string($ticket->merged_into ?? null)
                    ? DB::table('tickets')->where('id', $ticket->merged_into)->value('number')
                    : null;
                // D12: the banner skips the middle — walk the chain to the
                // FINAL survivor (EC-03's transitive truth), bounded.
                $survivorId = $ticket->merged_into ?? null;
                $hops = 0;
                while (is_string($survivorId) && $hops < 10) {
                    $next = DB::table('tickets')->where('id', $survivorId)->value('merged_into');
                    if (! is_string($next)) {
                        break;
                    }
                    $survivorId = $next;
                    $hops++;
                }
                $row['final_survivor_id'] = is_string($survivorId) ? $survivorId : null;
                /** @var object{number: int|string, conversation_id: string}|null $survivor */
                $survivor = is_string($survivorId)
                    ? DB::table('tickets')->where('id', $survivorId)->first(['number', 'conversation_id'])
                    : null;
                $row['final_survivor_number'] = $survivor->number ?? null;
                $row['final_survivor_conversation_id'] = $survivor->conversation_id ?? null;
                $row['origin_ticket_number'] = is_string($ticket->origin_ticket_id ?? null)
                    ? DB::table('tickets')->where('id', $ticket->origin_ticket_id)->value('number')
                    : null;
                // B2-S04 banner data (step 14): decoded records + names +
                // DB-computed boundaries; the client renders, never derives.
                $row['spam_marked_by_name'] = is_string($ticket->spam_marked_by ?? null)
                    ? ($assigneeNames[$ticket->spam_marked_by] ?? null)
                    : null;
                $row['spam_unmark_until'] = is_string($ticket->spam_marked_at ?? null)
                    ? ($this->boundary((string) $ticket->spam_marked_at, 7))
                    : null;
                if (is_string($ticket->escalation ?? null)) {
                    /** @var array{path?: string, reason?: string, target?: string|null, at?: string, by?: string} $escalation */
                    $escalation = (array) json_decode((string) $ticket->escalation, true, 4);
                    $row['escalation'] = [
                        'path' => $escalation['path'] ?? null,
                        'reason' => $escalation['reason'] ?? null,
                        'at' => $escalation['at'] ?? null,
                        'by_name' => is_string($escalation['by'] ?? null) ? ($assigneeNames[$escalation['by']] ?? null) : null,
                        'target_name' => is_string($escalation['target'] ?? null) ? ($assigneeNames[$escalation['target']] ?? null) : null,
                    ];
                } else {
                    $row['escalation'] = null;
                }
                $row['reopen_window_until'] = is_string($ticket->closed_at ?? null)
                    ? $this->boundary((string) $ticket->closed_at, $reopenDays)
                    : null;
                $row['previous_ticket_number'] = is_string($ticket->previous_ticket_id ?? null)
                    ? DB::table('tickets')->where('id', $ticket->previous_ticket_id)->value('number')
                    : null;
                $row['subject_was_ai'] = ($ticket->subject_source ?? null) === 'ai'
                    || DB::table('events')->where('name', 'ticket.subject_changed')
                        ->where('payload->ticket_id', $ticket->id)
                        ->where('payload->subject_source', 'ai')->exists();

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

    /** A stamp + N days, computed by the DATABASE (C-11 — never app clocks). */
    private function boundary(string $timestamp, int $days): ?string
    {
        /** @var object{until: string|null}|null $row */
        $row = DB::selectOne(
            'SELECT (?::timestamptz + make_interval(days => ?))::text AS until',
            [$timestamp, $days],
        );

        return $row->until ?? null;
    }
}
