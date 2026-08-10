<?php

declare(strict_types=1);

namespace App\Domain\Inbox\ReadModels;

use Illuminate\Support\Facades\DB;

/**
 * The denormalized row builder (Doc 06 T-08's canonical row, Doc 07 T-01's
 * hydration source): RECOMPUTE-FROM-TRUTH — every value is read fresh from
 * the canonical rows via CanonicalTicketReader at apply time, never
 * accumulated from event payloads. That is what makes the projector
 * idempotent on redelivery BY CONSTRUCTION (C-09): the same event re-applied
 * recomputes the same state.
 *
 * THIS FILE DELIBERATELY REFERENCES NO TICKET MODEL OR TABLE: it assigns a
 * `status` key (the read model's own COPY column), and the
 * ticket-status-writer gate's design constraint says a file doing that must
 * not also touch the ticket context — reads live in CanonicalTicketReader,
 * the copy assignment lives here, and tickets.status is written by neither
 * (NFR-03's line, kept visible).
 *
 * Pinned-provenance copies (Schema Amendment 1): ticket_created_at,
 * last_activity_at, last_message_created_at, window_expires_at — byte-copies
 * of canonical DB-time stamps, never fresh clock reads. sla_due_at/sla_state
 * are NOT touched here: fixture-fed until Doc 12 produces (Amendment rider
 * (ii)); a recompute must not erase them.
 *
 * EC-11: a media-only last message stores its KIND as the snippet
 * placeholder key ("photo" → the renderer localizes "📷 Photo"), never a raw
 * storage key. Notes and system events are never snippets (FR-09).
 *
 * @phpstan-import-type TicketTruth from CanonicalTicketReader
 *
 * @phpstan-type ListItemRow object{
 *     ticket_id: string,
 *     tenant_id: string,
 *     conversation_id: string,
 *     number: int|string,
 *     status: string,
 *     priority: string,
 *     pending_reason: string|null,
 *     source: string,
 *     assignee_id: string|null,
 *     department_id: string|null,
 *     queue_id: string|null,
 *     customer_id: string|null,
 *     customer_display_name: string|null,
 *     channel: string,
 *     dual_channel: bool,
 *     channel_unreachable: bool,
 *     subject: string|null,
 *     snippet: string|null,
 *     snippet_kind: string,
 *     language: string|null,
 *     sentiment: string|null,
 *     sla_state: string|null,
 *     ticket_created_at: string,
 *     last_activity_at: string,
 *     last_message_created_at: string|null,
 *     window_expires_at: string|null,
 *     sla_due_at: string|null,
 *     created_at: string,
 *     updated_at: string|null
 * }
 */
final class TicketListItems
{
    /** Columns the upsert may write — exactly the granted UPDATE column list. */
    private const UPDATABLE = [
        'conversation_id', 'number', 'status', 'priority', 'pending_reason', 'source',
        'assignee_id', 'department_id', 'customer_id', 'customer_display_name',
        'channel', 'channel_unreachable', 'subject', 'snippet', 'snippet_kind',
        'language', 'ticket_created_at', 'last_activity_at', 'last_message_created_at',
        'window_expires_at', 'updated_at',
    ];

    public function __construct(private readonly CanonicalTicketReader $reader) {}

    /**
     * Recompute one ticket's row from truth and upsert it. Returns the fresh
     * row (reread after write) or null when the ticket does not exist.
     *
     * @return ListItemRow|null
     */
    public function recompute(string $ticketId): ?object
    {
        $truth = $this->reader->read($ticketId);
        if ($truth === null) {
            return null;
        }

        $t = $truth->ticket;
        $snippetKind = $truth->lastPublic->kind ?? 'text';

        $values = [
            'tenant_id' => $t->tenant_id,
            'conversation_id' => $t->conversation_id,
            'number' => $t->number,
            'status' => $t->status,
            'priority' => $t->priority,
            'pending_reason' => $t->pending_reason,
            'source' => $t->source,
            'assignee_id' => $t->assignee_id,
            'department_id' => $t->department_id,
            'customer_id' => $t->customer_id,
            'customer_display_name' => $truth->customer->display_name ?? null,
            'channel' => $truth->conversation->channel ?? 'unknown',
            'channel_unreachable' => (bool) $t->channel_unreachable,
            'subject' => $t->subject,
            // EC-11: text bodies snip to 140 chars; media-only stores NO body
            // text — the kind is the localized-placeholder key downstream.
            'snippet' => $snippetKind === 'text' ? mb_substr((string) ($truth->lastPublic->body ?? ''), 0, 140) : null,
            'snippet_kind' => $snippetKind,
            'language' => null,
            'ticket_created_at' => $t->created_at,
            'last_activity_at' => $truth->lastActivityAt,
            'last_message_created_at' => $truth->lastPublic->created_at ?? null,
            'window_expires_at' => $truth->conversation->reply_window_expires_at ?? null,
            'updated_at' => DB::raw('clock_timestamp()'),
        ];

        DB::table('ticket_list_items')->upsert(
            [array_merge(['ticket_id' => $ticketId], $values)],
            ['ticket_id'],
            self::UPDATABLE,
        );

        /** @var ListItemRow|null $fresh */
        $fresh = DB::table('ticket_list_items')->where('ticket_id', $ticketId)->first();

        return $fresh;
    }
}
