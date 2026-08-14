<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

use Illuminate\Support\Facades\DB;

/**
 * THE SINGLE SOURCE (AC-04's law): the typed confirmation's content and the
 * unlink's actual move are BOTH computed HERE, from one query — the
 * confirmation can therefore never describe a different set than the one
 * that moves. SABOTAGE #7 computes the preview from a different query and
 * the AC-04 identity proof goes red.
 *
 * THE ANCHOR RULE (filed interpretation A-42, this step's commit): FR-10
 * presumes conversations are attributable to the identity that anchors them
 * but the schema stores no identity_id on conversations. The derivation is
 * CHANNEL PHYSICS: a conversation is anchored by the channel identity whose
 * external user id equals the conversation's external_chat_id on the same
 * channel — Telegram private-chat physics (chat id == user id for the
 * Business and direct surfaces Doc 05 built); Doc 13's web identities anchor
 * by visitor id the same way when they land. phone/email identities anchor
 * NO conversations in v1 (no channel produces them: SMS is notification-only
 * N-15, and nothing else speaks phone/email) — unlinking one moves the
 * identity alone.
 */
final class UnlinkMoveSet
{
    /**
     * @return array{identity: object{id: string, customer_id: string, type: string, value: string, channel: string},
     *               conversation_ids: list<string>, ticket_ids: list<string>, open_ticket_ids: list<string>}|null
     *         null = identity not found (the door 404s, C-10)
     */
    public function for(string $identityId): ?array
    {
        /** @var object{id: string, customer_id: string, type: string, value: string, channel: string}|null $identity */
        $identity = DB::table('customer_identities')
            ->where('id', $identityId)
            ->first(['id', 'customer_id', 'type', 'value', 'channel']);

        if ($identity === null) {
            return null;
        }

        $conversationIds = [];
        if (in_array($identity->type, ChannelIdentityMap::channelMintedTypes(), true)) {
            /** @var list<string> $conversationIds */
            $conversationIds = DB::table('conversations')
                ->where('customer_id', $identity->customer_id)
                ->where('channel', $identity->channel)
                ->where('external_chat_id', $identity->value)
                ->orderBy('created_at')
                ->pluck('id')
                ->all();
        }

        $ticketIds = [];
        $openTicketIds = [];
        if ($conversationIds !== []) {
            /** @var list<object{id: string, status: string}> $tickets */
            $tickets = DB::table('tickets')
                ->whereIn('conversation_id', $conversationIds)
                ->orderBy('created_at')
                ->get(['id', 'status'])
                ->all();

            foreach ($tickets as $ticket) {
                $ticketIds[] = $ticket->id;
                if (in_array($ticket->status, ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'], true)) {
                    $openTicketIds[] = $ticket->id;
                }
            }
        }

        return [
            'identity' => $identity,
            'conversation_ids' => $conversationIds,
            'ticket_ids' => $ticketIds,
            'open_ticket_ids' => $openTicketIds,
        ];
    }
}
