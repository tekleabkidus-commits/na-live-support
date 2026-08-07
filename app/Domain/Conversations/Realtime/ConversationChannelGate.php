<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Realtime;

use App\Domain\Tickets\Storage\TicketScope;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;

/**
 * T-06: the conversation.{id} subscribe callback enforces Doc 03 scope — Doc 04's
 * inherited row 2, RE-PROVEN here against real assignment columns.
 *
 * Order: permission verdict, then object scope, and every refusal is the same silent
 * false (C-10's posture at the socket layer: a denied subscriber learns nothing).
 *
 * WHAT DOC 06 CHANGED, AND THE PART THAT DELIBERATELY DID NOT CHANGE.
 *
 * Doc 04 shipped this gate FAIL-CLOSED: the conversations table had no assignment
 * columns, so any role scoped Own/Team/Dept resolved to an empty set and ONLY `All`
 * could subscribe. That was a stand-in, and its docblock said Doc 06 would re-prove it.
 *
 * Doc 06 supplies the missing fact — `tickets.assignee_id` — so `Own` becomes real:
 * an agent may subscribe to a conversation they are ASSIGNED work on, through the same
 * ScopeSets the list filter uses (R-03's rule that the object check and the list filter
 * can never diverge). That is the ADMIT half.
 *
 * The DENY half must not widen by accident, and it does not:
 *  - `Own` with no assigned ticket on that conversation is REFUSED. The stand-in used
 *    to refuse this by refusing everyone; now it refuses it on the merits, which is a
 *    strictly narrower rule with the same answer.
 *  - `Team`/`Dept` stay refused in production, because `MembershipSource` binds to
 *    `EmptyMembership` until Doc 11 supplies real membership. Nothing here widened
 *    them, and widening them before Doc 11 would be the open-fall Doc 03's NFR-04
 *    sabotage exists to catch.
 *  - An unknown conversation and a foreign one still look identical (C-10).
 */
final class ConversationChannelGate
{
    public function __construct(
        private readonly CapabilityMapProvider $verdicts,
        private readonly TicketScope $ticketScope,
    ) {}

    /** @return array{id: string, name: string}|false presence member info, or refusal */
    public function authorize(User $user, string $conversationId): array|false
    {
        if (! $this->verdicts->verdictFor($user, 'conversations.view')->allowed) {
            return false;
        }

        if (! Conversation::query()->whereKey($conversationId)->exists()) {
            return false; // unknown and foreign look identical (C-10)
        }

        if (! $this->withinScope($user, $conversationId)) {
            return false;
        }

        $name = $user->getAttribute('name');

        return [
            'id' => $user->identifier(),
            'name' => is_string($name) ? $name : '',
        ];
    }

    /**
     * A conversation is in scope when the user's conversations scope says so, or —
     * now that tickets carry assignment — when they hold a ticket on it that their
     * TICKET scope admits.
     *
     * The second clause is evaluated with ScopeSets rather than a hand-written
     * `assignee_id = ?`, so a role scoped Team or Dept gets the membership answer (the
     * empty set, pre-Doc 11) instead of accidentally being treated as Own.
     */
    private function withinScope(User $user, string $conversationId): bool
    {
        if ($this->verdicts->mapFor($user)->scopeFor('conversations') === 'All') {
            return true;
        }

        /** @var list<string> $ticketIds */
        $ticketIds = Ticket::query()
            ->where('conversation_id', $conversationId)
            ->pluck('id')
            ->all();

        foreach ($ticketIds as $ticketId) {
            if ($this->ticketScope->visible($user, $ticketId)) {
                return true;
            }
        }

        return false;
    }
}
