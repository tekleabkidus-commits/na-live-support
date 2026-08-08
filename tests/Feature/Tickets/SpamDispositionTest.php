<?php

declare(strict_types=1);

use App\Domain\Tickets\Metrics\MetricExclusion;
use App\Domain\Tickets\Spam\SpamTicket;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TransitionNotPermitted;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 06 AC-06 — spam disposition (step 7)
|--------------------------------------------------------------------------
|
| "AC-06 Spam: Doc 15 fixtures show metric exclusion on mark, restoration on unmark
| ≤ 7 d, terminality after." The golden half (exclusion values Doc 15's dictionary
| asserts) lives in MetricTimestampsTest's fixture driver; this file proves the
| OPERATION: both doors, the separate block, the terminality boundary, and EC-09's
| fresh-ticket path through the reopen consumer (A-17).
|
*/

test('FR-09: marking spam takes the machine edge, stamps the actor, excludes from metrics, and does NOT touch the conversation', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.spam.mark', 'tickets.view'], 'Fixture Spam Role');

    app(SpamTicket::class)->mark($ticket, $actor, 'promo-bot pasting betting links');

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Spam)
        ->and($ticket->spam_marked_at)->not->toBeNull('the R-20 trigger stamps the mark')
        ->and($ticket->spam_marked_by)->toBe($actor->identifier())
        ->and(app(MetricExclusion::class)->excluded($ticket))->toBeTrue('N-14: spam never counts')
        ->and($conversation->fresh()->open_ticket_id)->toBeNull('spam left the open family — the slot released');

    $event = json_decode((string) DB::table('outbox')->where('name', 'ticket.spam_marked')->first()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($event['conversation_blocked'])->toBeFalse()
        ->and(DB::table('outbox')->where('name', 'conversation.blocked')->count())->toBe(0, 'FR-09: the block is SEPARATE and was not chosen')
        ->and($conversation->fresh()->state)->toBe('active');

    // SEC-03: the reason is on the audit record.
    $audit = DB::table('audit_log')->where('action_key', 'ticket.spam_marked')->where('entity_id', $ticket->identifier())->first();
    expect($audit)->not->toBeNull();
    expect(json_decode((string) $audit->payload, true)['reason'])->toContain('promo-bot');
});

test('FR-09: the separately-confirmed conversation block rides Doc 04\'s own service when chosen', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.spam.mark', 'tickets.view'], 'Fixture Spam Role');

    app(SpamTicket::class)->mark($ticket, $actor, 'promo-bot pasting betting links', blockConversation: true);

    expect($conversation->fresh()->state)->toBe('blocked', 'the chosen block must land through Doc 04')
        ->and(DB::table('outbox')->where('name', 'conversation.blocked')->count())->toBe(1, 'Doc 04\'s own event fires — a separate act with its own trail');

    $event = json_decode((string) DB::table('outbox')->where('name', 'ticket.spam_marked')->first()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($event['conversation_blocked'])->toBeTrue();
});

test('FR-09: unmark within 7 days restores the PRIOR status from event history and re-includes metrics — both prior statuses', function (string $priorStatus) {
    [$tenant, $conversation, $ticket] = ticketFixture($priorStatus);
    $actor = ticketActor(['tickets.spam.mark', 'tickets.view'], 'Fixture Spam Role');

    app(SpamTicket::class)->mark($ticket, $actor, 'looked like junk at first glance');
    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Spam);

    app(SpamTicket::class)->unmark($ticket, $actor, 'real player — mismarked, restoring');

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::from($priorStatus), 'A-10/FR-09: the PRIOR status returns, not a hardcoded one')
        ->and($ticket->spam_marked_at)->toBeNull('the trigger clears the mark with the exit')
        ->and($ticket->spam_marked_by)->toBeNull()
        ->and(app(MetricExclusion::class)->excluded($ticket))->toBeFalse('metrics re-include by the same predicate')
        ->and($conversation->fresh()->open_ticket_id)->toBe($ticket->identifier(), 'the restored ticket re-claims the FR-10 slot');

    $event = json_decode((string) DB::table('outbox')->where('name', 'ticket.spam_unmarked')->first()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($event['restored_status'])->toBe($priorStatus);

    // SEC-03: "Spam-unmark … always audited with actor + old/new."
    expect(DB::table('audit_log')->where('action_key', 'ticket.spam_unmarked')->where('entity_id', $ticket->identifier())->count())->toBe(1);
})->with(['new', 'open']);

test('EC-09: unmark on day 8 is refused with the terminality explanation, and nothing mutates', function () {
    [$tenant, , $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $actor = ticketActor(['tickets.spam.mark', 'tickets.view'], 'Fixture Spam Role');

    app(SpamTicket::class)->mark($ticket, $actor, 'promo-bot pasting betting links');

    commitFixtures();

    try {
        // Day 8: only the platform role can move the R-19b mark stamp.
        DB::connection('pgsql_platform')->update(
            "UPDATE tickets SET spam_marked_at = spam_marked_at - interval '8 days' WHERE id = ?",
            [$ticket->identifier()],
        );

        expect(fn () => app(SpamTicket::class)->unmark($ticket->fresh(), $actor, 'trying too late'))
            ->toThrow(TransitionRefused::class, 'spam_terminal');

        $ticket->refresh();
        expect($ticket->status())->toBe(TicketStatus::Spam, 'terminal means terminal')
            ->and(app(MetricExclusion::class)->excluded($ticket))->toBeTrue('terminal spam stays excluded forever');
    } finally {
        cleanupTenants([$tenantId]);
    }
});

test('EC-09/A-17: a customer message after TERMINAL spam opens a FRESH ticket — unlinked, automation-sourced, idempotent', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $actor = ticketActor(['tickets.spam.mark', 'tickets.view'], 'Fixture Spam Role');

    app(SpamTicket::class)->mark($ticket, $actor, 'promo-bot pasting betting links');

    commitFixtures();

    try {
        DB::connection('pgsql_platform')->update(
            "UPDATE tickets SET spam_marked_at = spam_marked_at - interval '8 days' WHERE id = ?",
            [$ticket->identifier()],
        );

        $messageId = reopenCustomerMessage($tenantId, $conversation->identifier());
        $eventId = reopenSeedEvent($conversation->fresh(), $messageId);
        runReopenConsumer($eventId);

        /** @var Ticket $fresh */
        $fresh = Ticket::query()
            ->where('conversation_id', $conversation->identifier())
            ->where('id', '!=', $ticket->identifier())
            ->firstOrFail();

        expect($fresh->status())->toBe(TicketStatus::New)
            ->and($fresh->source)->toBe('automation', 'A-17 via A-12\'s reasoning')
            ->and($fresh->previous_ticket_id)->toBeNull('A-17: FRESH means unlinked — excluded history stays out of the live thread')
            ->and($conversation->fresh()->open_ticket_id)->toBe($fresh->identifier());

        // C-09: redelivery mints nothing.
        runReopenConsumer($eventId);
        expect(Ticket::query()->where('conversation_id', $conversation->identifier())->count())->toBe(2);
    } finally {
        cleanupTenants([$tenantId]);
    }
});

test('A-17: while the spam is still UNMARKABLE, a customer message takes no ticket action — the unmark restore owns the slot', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.spam.mark', 'tickets.view'], 'Fixture Spam Role');

    app(SpamTicket::class)->mark($ticket, $actor, 'looked like junk at first glance');

    // Day 1: the customer writes. The message lands; no fresh ticket.
    $messageId = reopenCustomerMessage($tenant->identifier(), $conversation->identifier());
    runReopenConsumer(reopenSeedEvent($conversation->fresh(), $messageId));

    expect(Ticket::query()->where('conversation_id', $conversation->identifier())->count())->toBe(1, 'A-17: no ticket while the unmark could still restore one');

    // And the unmark indeed restores into the free slot.
    app(SpamTicket::class)->unmark($ticket->fresh(), $actor, 'real player — mismarked, restoring');
    expect($conversation->fresh()->open_ticket_id)->toBe($ticket->identifier());
});

test('FR-09: the machine edge refuses a mark without tickets.spam.mark, and unmark refuses a non-spam ticket', function () {
    [, , $ticket] = ticketFixture('open');
    $unblessed = ticketActor(['tickets.view'], 'Fixture No-Spam Role');

    expect(fn () => app(SpamTicket::class)->mark($ticket, $unblessed, 'trying without the key'))
        ->toThrow(TransitionNotPermitted::class);

    $blessed = ticketActor(['tickets.spam.mark', 'tickets.view'], 'Fixture Spam Role');
    expect(fn () => app(SpamTicket::class)->unmark($ticket, $blessed, 'nothing to unmark'))
        ->toThrow(TransitionRefused::class, 'not_spam');
});
