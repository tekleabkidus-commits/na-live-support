<?php

declare(strict_types=1);

namespace App\Domain\Inbox\ReadModels;

use App\Domain\Sla\Chips;
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
 *     updated_at: string|null,
 *     tags: string,
 *     older_channel_activity: bool
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
        'window_expires_at', 'updated_at', 'tags',
    ];

    /**
     * P-CHIP (S12 batch D, countersigned at the batch-C acceptance): the SLA
     * chip columns are fed from Doc 12's TRUTH on every recompute of a ticket
     * that HOLDS an instance — Chips::forTicket's state (amber → warned, red →
     * breached, green/none → null) and its target as sla_due_at. Values from
     * truth, never from an event payload (the RollupConsumer posture: a
     * poisoned sla.warned due_at cannot land). A ticket with NO instance keeps
     * whatever its row holds — the browser seeders' hand-paint stays until the
     * fidelity session (stated in the packet). The CLEAR needs no arm of its
     * own: staff reply / status change already recompute the row, and the
     * rebuild re-reads a cleared stage as null. (Ordering note, stated: the
     * SLA lifecycle consumer and this projector ride the same event on the
     * same family; if the projector runs first the row heals on the ticket's
     * next event — the view membership is unaffected either way, since
     * sla_at_risk also requires the open family.)
     */
    public function __construct(
        private readonly CanonicalTicketReader $reader,
        private readonly Chips $chips,
    ) {}

    /**
     * The T-02 delta's row patch — assembled HERE (the context-free file) so
     * the projector, which touches the ticket tables, never assigns a
     * status-shaped key (the ticket-status-writer gate's design constraint,
     * the same split as recompute itself).
     *
     * @param  ListItemRow  $row
     * @return array<string, mixed>
     */
    public static function patchFor(object $row): array
    {
        return [
            'status' => $row->status,
            'priority' => $row->priority,
            'assignee_id' => $row->assignee_id,
            'subject' => $row->subject,
            'snippet' => $row->snippet,
            'snippet_kind' => $row->snippet_kind,
            'number' => $row->number,
            'last_activity_at' => $row->last_activity_at,
            'tags' => json_decode($row->tags, true, 8, JSON_THROW_ON_ERROR),
            // FR-09 line-2 chips + the Doc 05 EC-04 group flags (step 9):
            // the client patches badges in place, never re-derives them.
            'language' => $row->language,
            'sentiment' => $row->sentiment,
            'sla_state' => $row->sla_state,
            'sla_due_at' => $row->sla_due_at,
            'window_expires_at' => $row->window_expires_at,
            'customer_display_name' => $row->customer_display_name,
            'channel' => $row->channel,
            'dual_channel' => (bool) $row->dual_channel,
            'older_channel_activity' => (bool) $row->older_channel_activity,
        ];
    }

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
            // FR-09's language chip: the LAST PUBLIC message's detected
            // language (Doc 04's detector wrote it); null hides the chip.
            'language' => $truth->lastPublic->language ?? null,
            'ticket_created_at' => $t->created_at,
            'last_activity_at' => $truth->lastActivityAt,
            'last_message_created_at' => $truth->lastPublic->created_at ?? null,
            'window_expires_at' => $truth->conversation->reply_window_expires_at ?? null,
            'tags' => $truth->tagsJson,
            'updated_at' => DB::raw('clock_timestamp()'),
        ];

        // P-CHIP: the chip columns, from Doc 12's truth, ONLY for a ticket that holds an instance.
        $updatable = self::UPDATABLE;
        if ($this->chips->hasInstance($ticketId)) {
            $chip = $this->chips->forTicket($ticketId);
            $values['sla_state'] = match ($chip['state'] ?? null) {
                'amber' => 'warned',
                'red' => 'breached',
                default => null,
            };
            $values['sla_due_at'] = $chip['target_at'] ?? null;
            $updatable[] = 'sla_state';
            $updatable[] = 'sla_due_at';
        }

        DB::table('ticket_list_items')->upsert(
            [array_merge(['ticket_id' => $ticketId], $values)],
            ['ticket_id'],
            $updatable,
        );

        /** @var ListItemRow|null $fresh */
        $fresh = DB::table('ticket_list_items')->where('ticket_id', $ticketId)->first();

        return $fresh;
    }
}
