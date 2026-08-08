<?php

declare(strict_types=1);

use App\Domain\Tickets\Resolution\ResolveTicket;
use App\Domain\Tickets\Timers\TicketTimers;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Ticket;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 FR-06 / BR-04 — resolution requires substance (step 5)
|--------------------------------------------------------------------------
|
| "resolved: requires either a public staff/AI reply in the ticket or an explicit
| resolution note (setting tickets.resolve.requires_reply default on); triggers CSAT
| flow (Doc 12) and SMS event ticket_resolved (Doc 14)."
|
| The trigger half is the ticket.resolved event (A-8: doubles as Doc 14's signal),
| asserted here at the outbox. The consumers do not exist yet and nothing here
| pretends they do.
|
| Also here: the FR-07 timer ARMS on resolve — deadline exactly resolved_at +
| tickets.autoclose.hours, in DB arithmetic — because arming is a resolve-time
| behaviour even though the sweep that consumes it is tested in ReopenAutoCloseTest.
|
*/

/** A public (non-note) staff/AI reply the FR-24 trigger will accept as a signal. */
function fr06PublicReply(string $tenantId, string $conversationId, string $authorType = 'agent'): string
{
    $id = (string) Str::uuid7();

    DB::table('messages')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'conversation_id' => $conversationId,
        'direction' => 'out',
        'author_type' => $authorType,
        'kind' => 'text',
        'body' => 'fixture public reply',
        'is_internal' => false,
        'delivery_state' => 'sent',
    ]);

    return $id;
}

beforeEach(function (): void {
    // The timer ZSET is process-global Redis state; a leftover member from another
    // test must not pollute member-presence assertions here.
    Redis::connection('durable')->del(TicketTimers::ZSET);
});

test('FR-06: a bare resolve with no public reply and no note is refused (setting on, its default)', function () {
    [, , $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.status.change'], 'Fixture Resolver Role');

    expect(fn () => app(ResolveTicket::class)->resolve($ticket, $actor))
        ->toThrow(TransitionRefused::class, 'resolution_requires_substance');

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Open, 'a refused resolve must not move the ticket');
    expect(DB::table('outbox')->where('name', 'ticket.resolved')->count())->toBe(0);
});

test('FR-06: an explicit resolution note satisfies the substance requirement, and the note is written with the resolve', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.status.change'], 'Fixture Resolver Role');

    $result = app(ResolveTicket::class)->resolve($ticket, $actor, 'Player was refunded 250 ETB; case closed with finance ref 4471.');

    expect($result->ticket->status())->toBe(TicketStatus::Resolved);

    // The note exists, in the conversation, as an INTERNAL note (N-04's type — it
    // cannot be delivered to the customer; that is the note's whole species).
    $note = DB::table('messages')
        ->where('conversation_id', $conversation->identifier())
        ->where('kind', 'note')
        ->first();
    expect($note)->not->toBeNull('the resolution note was not written')
        ->and($note->is_internal)->toBeTrue();

    // FR-06's trigger signal for Doc 12's CSAT and Doc 14's SMS.
    expect(DB::table('outbox')->where('name', 'ticket.resolved')->count())->toBe(1);
});

test('FR-06: a prior public staff reply satisfies the requirement with no note', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.status.change'], 'Fixture Resolver Role');

    // The reply is recorded the way the real reply path records it: the signal
    // column, stamped by the R-20 trigger into first_response_at.
    $messageId = fr06PublicReply($tenant->identifier(), $conversation->identifier());
    app(TicketTransitionService::class)->recordPublicReply($ticket, $messageId, isHuman: true);
    $ticket->refresh();
    expect($ticket->first_response_at)->not->toBeNull('fixture failed: the reply did not stamp');

    $result = app(ResolveTicket::class)->resolve($ticket, $actor);

    expect($result->ticket->status())->toBe(TicketStatus::Resolved);
});

test('FR-06: an AI public reply is substance too — the definition is FR-24\'s, which counts staff AND ai', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.status.change'], 'Fixture Resolver Role');

    $messageId = fr06PublicReply($tenant->identifier(), $conversation->identifier(), 'ai');
    app(TicketTransitionService::class)->recordPublicReply($ticket, $messageId, isHuman: false);
    $ticket->refresh();
    expect($ticket->first_response_at)->not->toBeNull('fixture failed: the AI reply did not stamp');

    $result = app(ResolveTicket::class)->resolve($ticket, $actor);

    expect($result->ticket->status())->toBe(TicketStatus::Resolved);
});

test('FR-06: with tickets.resolve.requires_reply off, a bare resolve is allowed (R-21 operator policy)', function () {
    [, , $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.status.change'], 'Fixture Resolver Role');

    app(SettingsRepository::class)->set('tickets.resolve.requires_reply', false);

    $result = app(ResolveTicket::class)->resolve($ticket, $actor);

    expect($result->ticket->status())->toBe(TicketStatus::Resolved);
});

test('FR-07/T-03: entering resolved arms the auto-close timer at EXACTLY resolved_at + tickets.autoclose.hours, column and ZSET agreeing', function () {
    [$tenant, , $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.status.change'], 'Fixture Resolver Role');

    app(ResolveTicket::class)->resolve($ticket, $actor, 'resolution note for the arm test');
    $ticket->refresh();

    expect($ticket->auto_close_at)->not->toBeNull('resolve did not arm the timer');

    // EXACT, in the database's own arithmetic — not "roughly 48 hours from now",
    // which would smuggle an app clock into the assertion.
    /** @var object{exact: bool} $row */
    $row = DB::selectOne(
        'SELECT (auto_close_at = resolved_at + make_interval(hours => 48)) AS exact FROM tickets WHERE id = ?',
        [$ticket->identifier()],
    );
    expect($row->exact)->toBeTrue('auto_close_at is not resolved_at + the 48 h default');

    // The ZSET candidate exists, scored with the deadline's integer microseconds
    // (the R-23 family principle), on the DURABLE instance (T-03).
    $member = $tenant->identifier().'|'.$ticket->identifier();
    expect(ticketTimerScore($member))->toBe((int) $ticket->auto_close_at->format('Uu'), 'no ZSET candidate at the armed deadline');
});

test('ticket.resolved / ticket.closed / ticket.reopened ride EVERY door of the sole writer, not just the named operations (SS-04)', function () {
    [, , $ticket] = ticketFixture('open');
    $service = app(TicketTransitionService::class);

    // Drive the machine directly — the barest possible door.
    $service->transition($ticket, TicketStatus::Resolved, App\Domain\Tickets\Transitions\TransitionContext::system('ss04 probe'));
    $ticket->refresh();
    $service->transition($ticket, TicketStatus::Closed, App\Domain\Tickets\Transitions\TransitionContext::system('ss04 probe'));
    $ticket->refresh();

    $staff = ticketActor(['tickets.reopen'], 'Fixture Reopen Role');
    $service->transition($ticket, TicketStatus::Open, App\Domain\Tickets\Transitions\TransitionContext::staff($staff, 'ss04 probe reason'));
    $ticket->refresh();

    expect(DB::table('outbox')->where('name', 'ticket.resolved')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'ticket.closed')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'ticket.reopened')->count())->toBe(1)
        ->and($ticket->reopened_count)->toBe(1, 'the staff reopen did not increment reopened_count');

    // FR-09's unmark restores — it does NOT reopen. spam->open must not count.
    [, , $spamTicket] = ticketFixture('spam');
    $unmarker = ticketActor(['tickets.spam.mark'], 'Fixture Spam Role');
    $service->transition($spamTicket, TicketStatus::Open, App\Domain\Tickets\Transitions\TransitionContext::staff($unmarker, 'unmark within window'));
    $spamTicket->refresh();

    expect($spamTicket->reopened_count)->toBe(0, 'a spam unmark was counted as a reopen')
        ->and(DB::table('outbox')->where('name', 'ticket.reopened')->count())->toBe(0, 'a spam unmark emitted ticket.reopened');
});
