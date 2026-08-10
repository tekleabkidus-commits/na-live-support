<?php

declare(strict_types=1);

namespace App\Domain\Inbox\ReadModels;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The seven system views (Doc 07 FR-05), each defined ONCE in two executable
 * forms that must agree — that agreement IS NFR-07:
 *
 *   - directQuery(): the membership predicate over the CANONICAL rows —
 *     what the consistency checker, the replay-counting proof (T-03/T-01)
 *     and FS-02's rebuild all run.
 *   - The projector evaluates the same predicate per ticket from the freshly
 *     recomputed list-item row (ListProjector) — recompute-from-truth, so a
 *     redelivered event recomputes the same state (C-09 by construction).
 *
 * Definitions, verbatim from FR-05 (+ the rulings that bind them):
 *   my_open:{user}    assignee = user AND status IN open-family; sort
 *                     last_activity desc (FR-10).
 *   unassigned        assignee IS NULL AND status IN open-family; sort
 *                     ticket created asc — "first-come" (FR-10/BR-04).
 *                     Scope-visibility applies at read per BR-03; the base
 *                     set here is the tenant-wide membership, per-scope
 *                     variants are A-30's step-4 lazy materialization.
 *   mentions:{user}   tickets with an UNRESOLVED mention for the user
 *                     (A-32: unresolved from note.mentioned until thread
 *                     open or bell dismiss — the mention rows live in
 *                     inbox_notifications, read_at as the resolution latch).
 *   following:{user}  tickets the user follows (ticket_followers truth).
 *   sla_at_risk       sla_state IN (warned, breached) AND open-family; sort
 *                     time-to-breach asc = sla_due_at asc. CANONICAL SOURCE
 *                     NOTE: until Doc 12 produces, the seam columns on
 *                     ticket_list_items ARE the canonical SLA data
 *                     (fixture-fed — Schema Amendment 1 rider (ii)).
 *   expiring_windows  conversations.reply_window_state IN (closing, expired)
 *                     with an open-family ticket (Doc 05 FR-22 / FR-05);
 *                     sort window expiry asc.
 *   rescue            tickets.channel_unreachable = true (Doc 05 FR-10's
 *                     rescue data); sort last_activity desc.
 *
 * LAST ACTIVITY, defined once (FR-10 "My Open = last activity desc"):
 * GREATEST(ticket.last_status_changed_at, conversation.last_customer_message_at,
 * conversation.last_outbound_at) — all DB-time stamps; the projector copies
 * the same expression into ticket_list_items.last_activity_at (pinned
 * provenance).
 */
final class SystemViews
{
    public const OPEN_FAMILY = ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'];

    /** The base (tenant-wide) view keys — per-scope variants are step 4 (A-30). */
    public const BASE_VIEWS = ['unassigned', 'sla_at_risk', 'expiring_windows', 'rescue'];

    /** Per-user view key. */
    public static function userView(string $view, string $userId): string
    {
        return sprintf('%s:%s', $view, $userId);
    }

    /**
     * The canonical-row query whose ids ARE the view's membership (NFR-07's
     * comparison side; FS-02's rebuild source). Returns a builder selecting
     * ticket ids + the sort stamp as `sort_at`.
     */
    public static function directQuery(string $view, ?string $userId = null): Builder
    {
        $lastActivity = 'GREATEST(t.last_status_changed_at, COALESCE(c.last_customer_message_at, t.created_at), COALESCE(c.last_outbound_at, t.created_at))';

        return match ($view) {
            'my_open' => DB::table('tickets as t')
                ->join('conversations as c', 'c.id', '=', 't.conversation_id')
                ->where('t.assignee_id', $userId)
                ->whereIn('t.status', self::OPEN_FAMILY)
                ->selectRaw("t.id, {$lastActivity} AS sort_at"),

            'unassigned' => DB::table('tickets as t')
                ->whereNull('t.assignee_id')
                ->whereIn('t.status', self::OPEN_FAMILY)
                ->selectRaw('t.id, t.created_at AS sort_at'),

            'mentions' => DB::table('inbox_notifications as n')
                ->join('tickets as t', 't.id', '=', 'n.ticket_id')
                ->where('n.user_id', $userId)
                ->where('n.category', 'notify.inbox.mention')
                ->whereNull('n.read_at')
                ->selectRaw('DISTINCT ON (t.id) t.id, n.created_at AS sort_at')
                ->orderByRaw('t.id, n.created_at DESC'),

            'following' => DB::table('ticket_followers as f')
                ->join('tickets as t', 't.id', '=', 'f.ticket_id')
                ->join('conversations as c', 'c.id', '=', 't.conversation_id')
                ->where('f.user_id', $userId)
                ->selectRaw("t.id, {$lastActivity} AS sort_at"),

            'sla_at_risk' => DB::table('ticket_list_items as li')
                ->join('tickets as t', 't.id', '=', 'li.ticket_id')
                ->whereIn('li.sla_state', ['warned', 'breached'])
                ->whereIn('t.status', self::OPEN_FAMILY)
                ->selectRaw('t.id, li.sla_due_at AS sort_at'),

            'expiring_windows' => DB::table('tickets as t')
                ->join('conversations as c', 'c.id', '=', 't.conversation_id')
                ->whereIn('c.reply_window_state', ['closing', 'expired'])
                ->whereIn('t.status', self::OPEN_FAMILY)
                ->selectRaw('t.id, c.reply_window_expires_at AS sort_at'),

            'rescue' => DB::table('tickets as t')
                ->join('conversations as c', 'c.id', '=', 't.conversation_id')
                ->where('t.channel_unreachable', true)
                ->selectRaw("t.id, {$lastActivity} AS sort_at"),

            default => throw new \InvalidArgumentException("unknown system view: {$view}"),
        };
    }

    /** Whether the read of this view is descending (FR-10's defaults). */
    public static function descending(string $view): bool
    {
        return in_array($view, ['my_open', 'following', 'rescue', 'mentions'], true);
    }
}
