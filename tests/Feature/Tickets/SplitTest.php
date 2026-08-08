<?php

declare(strict_types=1);

use App\Domain\Tickets\Split\SplitTicket;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 06 AC-05 — split (step 6)
|--------------------------------------------------------------------------
|
| "AC-05 Split: linked-work flag, cap, anchors resolving to the exact selected
| messages, origin note present."
|
| The exactness proof is deliberate: the selection is NON-CONTIGUOUS (messages 1 and 3
| of 4), so a lazy [min, max] span would sweep in the unselected message between —
| the per-message anchors must resolve to the selection and nothing else.
|
*/

/** N customer messages in the conversation, returned in (created_at, id) order. */
function splitMessages(string $tenantId, string $conversationId, int $count): array
{
    $ids = [];
    foreach (range(1, $count) as $i) {
        $ids[] = reopenCustomerMessage($tenantId, $conversationId);
    }

    return $ids;
}

test('AC-05: the split target carries linked_work + origin_ticket_id, takes no FR-10 slot, and its anchors resolve to EXACTLY the selection', function () {
    [$tenant, $conversation, $origin] = ticketFixture('open');
    $actor = ticketActor(['tickets.split', 'tickets.view'], 'Fixture Split Role');

    $messages = splitMessages($tenant->identifier(), $conversation->identifier(), 4);
    $selection = [$messages[0], $messages[2]];   // non-contiguous, deliberately

    $ticket = app(SplitTicket::class)->split($origin, $selection, $actor, subject: 'Withdrawal half of the complaint');

    expect($ticket->linked_work)->toBeTrue('AC-05: the split target must carry linked_work')
        ->and($ticket->origin_ticket_id)->toBe($origin->identifier())
        ->and($ticket->status())->toBe(TicketStatus::New)
        ->and($ticket->conversation_id)->toBe($conversation->identifier(), 'FR-21: same conversation')
        ->and($conversation->fresh()->open_ticket_id)->toBe($origin->identifier(), 'the origin keeps the FR-10 slot — linked work takes none');

    // AC-05, LITERALLY: the anchors resolve to the exact selected messages.
    $anchored = DB::table('ticket_message_anchors')
        ->where('ticket_id', $ticket->identifier())
        ->where('kind', 'split_selection')
        ->get(['from_message_id', 'to_message_id']);

    $resolved = $anchored->map(function (object $a): string {
        expect($a->from_message_id)->toBe($a->to_message_id, 'per-message anchors: from = to');

        return (string) $a->from_message_id;
    })->sort()->values()->all();

    $expected = $selection;
    sort($expected);
    expect($resolved)->toBe($expected, 'the anchors must resolve to the exact selection — no more, no fewer')
        ->and($resolved)->not->toContain($messages[1], 'the unselected message between the selected two must NOT be swept in');

    // "origin gets a system note + link": the platform's own voice, in the thread.
    $note = DB::table('messages')
        ->where('conversation_id', $conversation->identifier())
        ->where('kind', 'note')
        ->where('author_type', 'system')
        ->first();
    expect($note)->not->toBeNull('AC-05: the origin system note must exist')
        ->and($note->is_internal)->toBeTrue()
        // toContain takes NO message argument (the ledger's R-19b matcher lesson) —
        // a second string would be a second required needle.
        ->and($note->body)->toContain('#'.$ticket->number);

    expect(DB::table('outbox')->where('name', 'ticket.split')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'ticket.created')->count())->toBe(1, 'the new row is born through CreateTicket, evented as ever');
});

test('EC-04: the fourth live linked-work split is refused with guidance, and resolving one frees the slot', function () {
    [$tenant, $conversation, $origin] = ticketFixture('open');
    $actor = ticketActor(['tickets.split', 'tickets.view'], 'Fixture Split Role');
    $tenantId = $tenant->identifier();

    $messages = splitMessages($tenantId, $conversation->identifier(), 4);

    $splits = [];
    foreach (range(1, 3) as $i) {
        $splits[] = app(SplitTicket::class)->split($origin, [$messages[$i - 1]], $actor);
    }

    expect(Ticket::query()->where('conversation_id', $conversation->identifier())->where('linked_work', true)->count())->toBe(3);

    // The fourth is EC-04's refusal.
    expect(fn () => app(SplitTicket::class)->split($origin, [$messages[3]], $actor))
        ->toThrow(TransitionRefused::class, 'split_cap_reached');

    // "resolve or transfer existing linked work first" — the guidance is real:
    // resolving one linked ticket frees a slot and the same split then succeeds.
    // (Split targets are born `new`; resolution walks the machine's edges.)
    $service = app(TicketTransitionService::class);
    $service->transition($splits[0], TicketStatus::Open, TransitionContext::system('ec04 free a slot'));
    $service->transition($splits[0]->fresh(), TicketStatus::Resolved, TransitionContext::system('ec04 free a slot'));

    $fourth = app(SplitTicket::class)->split($origin, [$messages[3]], $actor);
    expect($fourth->linked_work)->toBeTrue()
        ->and(Ticket::query()->where('conversation_id', $conversation->identifier())->where('linked_work', true)->count())->toBe(4, 'the cap counts LIVE linked work, not historical');
});

test('FR-21: a selection containing another conversation\'s message is refused before anything is created', function () {
    [$tenant, $conversation, $origin] = ticketFixture('open');
    $actor = ticketActor(['tickets.split', 'tickets.view'], 'Fixture Split Role');

    $own = reopenCustomerMessage($tenant->identifier(), $conversation->identifier());
    $elsewhere = anotherConversation($tenant->identifier(), 'foreign-msg');
    $foreign = reopenCustomerMessage($tenant->identifier(), $elsewhere->identifier());

    expect(fn () => app(SplitTicket::class)->split($origin, [$own, $foreign], $actor))
        ->toThrow(TransitionRefused::class, 'split_messages_foreign');

    expect(Ticket::query()->where('linked_work', true)->count())->toBe(0)
        ->and(DB::table('outbox')->where('name', 'ticket.split')->count())->toBe(0);
});

test('FR-21: tickets.split is required, and an empty selection is refused', function () {
    [$tenant, $conversation, $origin] = ticketFixture('open');
    $unblessed = ticketActor(['tickets.view'], 'Fixture No-Split Role');
    $messageId = reopenCustomerMessage($tenant->identifier(), $conversation->identifier());

    expect(fn () => app(SplitTicket::class)->split($origin, [$messageId], $unblessed))
        ->toThrow(DomainException::class, 'tickets.split');

    $blessed = ticketActor(['tickets.split', 'tickets.view'], 'Fixture Split Role');
    expect(fn () => app(SplitTicket::class)->split($origin, [], $blessed))
        ->toThrow(DomainException::class, 'no selected messages');
});
