<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Connections;

use Illuminate\Support\Facades\DB;

/**
 * FR-10 (inherited row 2): on a connection revoke, the open tickets on that
 * connection's conversations are flagged `channel_unreachable`, the outbound
 * lane freezes (via Doc 04's existing non-retryable classification — the send
 * path reads the flag), and the rescue view's DATA is prepared (Doc 07
 * renders it).
 *
 * ORTHOGONALITY (the whole point, and Doc 06's tests pin it): flagging
 * channel_unreachable changes ANSWERS, never STATE. This writer touches ONLY
 * the `channel_unreachable` boolean — it emits no ticket transition, no
 * ticket.status_changed, nothing that moves a ticket through its machine. A
 * human still decides; the flag just refuses the AUTOMATED resolve/close
 * (BR-07, enforced in TicketTransitionService).
 */
final class ChannelUnreachableWriter
{
    public function onRevoked(string $botId, string $connectionId): int
    {
        // The conversations on this connection.
        $conversationIds = DB::table('conversations')
            ->where('channel', 'telegram')
            ->where('business_connection_id', $connectionId)
            ->pluck('id');

        if ($conversationIds->isEmpty()) {
            return 0;
        }

        // Flag OPEN tickets only (the non-terminal family). A pure column
        // write — no status column is read or written, so no transition can
        // fire (Doc 06 EC-10 orthogonality).
        return DB::table('tickets')
            ->whereIn('conversation_id', $conversationIds)
            ->whereIn('status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'])
            ->update([
                'channel_unreachable' => true,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
    }

    /** FR-24/reopen: clear the flag when the channel comes back (a customer message reopens). */
    public function clear(string $connectionId): int
    {
        $conversationIds = DB::table('conversations')
            ->where('channel', 'telegram')
            ->where('business_connection_id', $connectionId)
            ->pluck('id');

        if ($conversationIds->isEmpty()) {
            return 0;
        }

        return DB::table('tickets')
            ->whereIn('conversation_id', $conversationIds)
            ->where('channel_unreachable', true)
            ->update([
                'channel_unreachable' => false,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
    }
}
