<?php

declare(strict_types=1);

use App\Domain\Tickets\Reopen\ReopenTicket;
use App\Domain\Tickets\Timers\TicketTimers;
use App\Domain\Tickets\Timers\TicketTimerSweep;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Domain\Tickets\Transitions\TransitionNotPermitted;
use App\Domain\Tickets\Transitions\TransitionRefused;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 AC-03 — reopen / auto-close (step 5)
|--------------------------------------------------------------------------
|
| "AC-03 Reopen/auto-close fixtures: EC-01/EC-02 boundaries pass; reopen re-notifies
| an active prior assignee, re-routes otherwise."
|
| EC-01  the sweep LOSES to a persisted customer reply — cancel wins IN the sweep's
|        own transaction, before any close exists to flicker back from. This file's
|        EC-01 test is sabotage #6's target: remove the sweep's in-transaction
|        re-check and it goes red.
| EC-02  the reopen boundary is closed_at + tickets.reopen.days, DB-time compared
|        against the MESSAGE's own created_at, INCLUSIVE at the exact instant —
|        pinned here to the second on both sides of the line.
| R-03   all-UTC storage makes the window exactly 168 h across any DST date —
|        probed with pinned DST-spanning dates and proven end-to-end on one.
|
| Fixtures that pin trigger-stamped columns (closed_at, resolved_at, auto_close_at)
| go through pgsql_platform — the app role cannot name them (R-19b), which is the
| point — and therefore commit first (commitFixtures) and clean up in a finally,
| the MetricTimestampsTest pattern.
|
*/

/*
| The shared shapes — reopenCustomerMessage(), reopenSeedEvent(),
| runReopenConsumer(), machineClosedTicket() — live in tests/Pest.php, because
| ReopenConsumerDrillTest needs the same fixtures and two file-local definitions
| would collide in Pest's single process.
*/

/** Pin closed_at relative to a message's DB-stamped created_at (platform — R-19a's escape hatch). */
function pinClosedAtRelativeToMessage(string $ticketId, string $messageId, string $offsetSql): void
{
    DB::connection('pgsql_platform')->update(
        "UPDATE tickets
            SET closed_at = (SELECT created_at FROM messages WHERE id = ?) {$offsetSql}
          WHERE id = ?",
        [$messageId, $ticketId],
    );
}

beforeEach(function (): void {
    Redis::connection('durable')->del(TicketTimers::ZSET);
});

// ── FR-07: the sweep closes what is genuinely due ────────────────────────────

test('FR-07: after the auto-close window of customer silence, the sweep closes the resolved ticket through the machine', function () {
    [$tenant, , $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    app(TicketTransitionService::class)->transition($ticket, TicketStatus::Resolved, TransitionContext::system('fr07 fixture'));
    $ticket->refresh();
    expect($ticket->auto_close_at)->not->toBeNull('fixture failed: resolve did not arm');

    commitFixtures();

    try {
        // 49 hours of silence, by moving the WORLD back rather than asserting on now.
        DB::connection('pgsql_platform')->update(
            "UPDATE tickets SET resolved_at = resolved_at - interval '49 hours', auto_close_at = auto_close_at - interval '49 hours' WHERE id = ?",
            [$ticket->identifier()],
        );
        $ticket->refresh();
        app(TicketTimers::class)->add($tenantId.'|'.$ticket->identifier(), (int) $ticket->auto_close_at->format('Uu'));

        $result = app(TicketTimerSweep::class)->sweep();

        expect($result['closed'])->toBe(1)->and($result['cancelled'])->toBe(0);

        $ticket->refresh();
        expect($ticket->status())->toBe(TicketStatus::Closed, 'the due timer did not close the ticket')
            ->and($ticket->closed_at)->not->toBeNull('closed_at was not stamped by the transition')
            ->and($ticket->auto_close_at)->toBeNull('the consumed timer was not cleared');

        // Through the machine, visibly: the transition event AND FR-26's ticket.closed.
        expect(DB::table('outbox')->where('name', 'ticket.closed')->count())->toBe(1);
        $audit = DB::table('audit_log')->where('action_key', 'ticket.status_changed')->where('entity_id', $ticket->identifier())->orderByDesc('id')->first();
        expect($audit)->not->toBeNull()->and($audit->actor_type)->toBe('system');

        expect(ticketTimerScore($tenantId.'|'.$ticket->identifier()))->toBeNull();
    } finally {
        cleanupTenants([$tenantId]);
    }
});

// ── EC-01: the event-driven cancel WINS at the exact sweep moment ────────────
// Sabotage #6's target: remove the sweep's in-transaction re-check → this goes red.

test('EC-01: a persisted customer reply beats the due sweep — no close, no flicker, and the lagging consumer reopens from resolved', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    app(TicketTransitionService::class)->transition($ticket, TicketStatus::Resolved, TransitionContext::system('ec01 fixture'));
    $ticket->refresh();

    commitFixtures();

    try {
        DB::connection('pgsql_platform')->update(
            "UPDATE tickets SET resolved_at = resolved_at - interval '49 hours', auto_close_at = auto_close_at - interval '49 hours' WHERE id = ?",
            [$ticket->identifier()],
        );
        $ticket->refresh();
        app(TicketTimers::class)->add($tenantId.'|'.$ticket->identifier(), (int) $ticket->auto_close_at->format('Uu'));

        // The reply is PERSISTED but its consumer has NOT run — the exact lag EC-01
        // exists for. The ticket still reads `resolved`; only the messages table
        // knows the silence ended.
        $messageId = reopenCustomerMessage($tenantId, $conversation->identifier());

        $result = app(TicketTimerSweep::class)->sweep();

        expect($result['cancelled'])->toBe(1, 'the sweep did not cancel against the persisted reply')
            ->and($result['closed'])->toBe(0, 'EC-01: the sweep closed over a persisted customer reply');

        $ticket->refresh();
        expect($ticket->status())->toBe(TicketStatus::Resolved, 'the sweep must leave the reopen to the consumer')
            ->and($ticket->auto_close_at)->toBeNull('the beaten timer must be disarmed')
            ->and($ticket->closed_at)->toBeNull('a closed_at stamp IS the flicker');

        // The consumer catches up late — and finds `resolved`, the flicker-free door.
        runReopenConsumer(reopenSeedEvent($conversation, $messageId));

        $ticket->refresh();
        expect($ticket->status())->toBe(TicketStatus::Open)
            ->and($ticket->reopened_count)->toBe(1);

        // THE EC-01 CLAUSE, LITERALLY: no close-then-reopen. Nothing ever closed.
        expect(DB::table('outbox')->where('name', 'ticket.closed')->count())->toBe(0, 'flicker: a ticket.closed event exists')
            ->and(DB::table('outbox')->where('name', 'ticket.reopened')->count())->toBe(1);

        $reopened = DB::table('outbox')->where('name', 'ticket.reopened')->first();
        $payload = json_decode((string) $reopened->payload, true, 512, JSON_THROW_ON_ERROR);
        expect($payload['from_status'])->toBe('resolved', 'the reopen must come from resolved — closed would mean flicker');
    } finally {
        cleanupTenants([$tenantId]);
    }
});

// ── FR-10/FR-07: customer reply on a resolved ticket (timer not yet due) ─────

test('FR-10: a customer message on a resolved ticket reopens it unconditionally, cancelling the armed timer (FR-07\'s cancel)', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    app(TicketTransitionService::class)->transition($ticket, TicketStatus::Resolved, TransitionContext::system('fr10 fixture'));
    $ticket->refresh();
    $member = $tenant->identifier().'|'.$ticket->identifier();
    expect(ticketTimerScore($member))->not->toBeNull('fixture failed: the resolve did not arm a candidate');

    $messageId = reopenCustomerMessage($tenant->identifier(), $conversation->identifier());
    runReopenConsumer(reopenSeedEvent($conversation, $messageId));

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Open)
        ->and($ticket->reopened_count)->toBe(1)
        ->and($ticket->auto_close_at)->toBeNull('reopening must cancel the armed auto-close')
        ->and($ticket->resolved_at)->toBeNull('FR-24: reopen clears resolved_at until re-resolved')
        ->and(ticketTimerScore($member))->toBeNull();

    // FR-10's other clause: reopened, not duplicated.
    expect(Ticket::query()->where('conversation_id', $conversation->identifier())->count())->toBe(1);
});

// ── EC-02: the reopen boundary, pinned to the second on both sides ───────────

test('EC-02: the boundary instant (closed_at + 7 d exactly) is IN; one second beyond is OUT and mints the FR-08 successor, idempotently', function () {
    [$tenant, $convBoundary] = ticketFixture('open');   // fixture ticket unused; we need the tenant + a conversation factory
    $tenantId = $tenant->identifier();

    commitFixtures();

    try {
        // Case 1 — EXACTLY on the boundary: message.created_at = closed_at + 7 d. IN.
        $onBoundary = anotherConversation($tenantId, 'ec02-on');
        $t1 = machineClosedTicket($onBoundary);
        $m1 = reopenCustomerMessage($tenantId, $onBoundary->identifier());
        pinClosedAtRelativeToMessage($t1->identifier(), $m1, '- make_interval(days => 7)');

        runReopenConsumer(reopenSeedEvent($onBoundary, $m1));
        $t1->refresh();
        expect($t1->status())->toBe(TicketStatus::Open, 'EC-02: the inclusive boundary instant was refused')
            ->and($t1->reopened_count)->toBe(1);

        // Case 2 — one second INSIDE: closed_at + 7 d - 1 s. IN.
        $inside = anotherConversation($tenantId, 'ec02-in');
        $t2 = machineClosedTicket($inside);
        $m2 = reopenCustomerMessage($tenantId, $inside->identifier());
        pinClosedAtRelativeToMessage($t2->identifier(), $m2, "- make_interval(days => 7) + interval '1 second'");

        runReopenConsumer(reopenSeedEvent($inside, $m2));
        $t2->refresh();
        expect($t2->status())->toBe(TicketStatus::Open)->and($t2->reopened_count)->toBe(1);

        // Case 3 — one second BEYOND: the same ticket is DONE; the message mints the
        // FR-08 successor carrying previous_ticket_id, in the same conversation.
        $beyond = anotherConversation($tenantId, 'ec02-out');
        $t3 = machineClosedTicket($beyond);
        $m3 = reopenCustomerMessage($tenantId, $beyond->identifier());
        pinClosedAtRelativeToMessage($t3->identifier(), $m3, "- make_interval(days => 7) - interval '1 second'");

        $eventId = reopenSeedEvent($beyond, $m3);
        runReopenConsumer($eventId);

        $t3->refresh();
        expect($t3->status())->toBe(TicketStatus::Closed, 'a beyond-window message must not reopen')
            ->and($t3->reopened_count)->toBe(0);

        /** @var Ticket $successor */
        $successor = Ticket::query()
            ->where('conversation_id', $beyond->identifier())
            ->where('id', '!=', $t3->identifier())
            ->firstOrFail();
        expect($successor->previous_ticket_id)->toBe($t3->identifier(), 'the successor must link back (FR-08)')
            ->and($successor->status())->toBe(TicketStatus::New)
            ->and($successor->source)->toBe('automation');   // filed interpretation A-12

        // Staff hit the same wall beyond the window (§4.2's "≤ window, perm").
        $staff = ticketActor(['tickets.reopen'], 'Fixture Reopen Role');
        expect(fn () => app(ReopenTicket::class)->reopenByStaff($t3, $staff, 'trying to reopen a done ticket'))
            ->toThrow(TransitionRefused::class, 'reopen_window_passed');

        // C-09: the same event delivered again creates NOTHING new — the successor
        // half rides FR-13's idempotency key, derived from the event id.
        runReopenConsumer($eventId);
        expect(Ticket::query()->where('conversation_id', $beyond->identifier())->count())
            ->toBe(2, 'a redelivered event minted a second successor — the idempotency key failed');
    } finally {
        cleanupTenants([$tenantId]);
    }
});

// ── FR-08: assignee = previous if active, else back to the queue ─────────────

test('AC-03: reopen keeps an ACTIVE prior assignee (ticket.reopened re-notifies them)', function () {
    [$tenant, $conversation] = ticketFixture('open');
    $keeper = User::factory()->create();

    $ticket = machineClosedTicket(anotherConversation($tenant->identifier(), 'keep'));
    Ticket::query()->whereKey($ticket->identifier())->update(['assignee_id' => $keeper->identifier()]);

    /** @var Conversation $conv */
    $conv = Conversation::query()->findOrFail($ticket->conversation_id);
    $messageId = reopenCustomerMessage($tenant->identifier(), $conv->identifier());
    runReopenConsumer(reopenSeedEvent($conv, $messageId));

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Open)
        ->and($ticket->assignee_id)->toBe($keeper->identifier(), 'an active prior assignee must be retained');

    $payload = json_decode((string) DB::table('outbox')->where('name', 'ticket.reopened')->orderByDesc('id')->first()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['assignee_id'])->toBe($keeper->identifier(), 'Doc 16 re-notifies whoever this event names');
});

test('AC-03: reopen onto a DEACTIVATED assignee hands the ticket back to the queue (EC-06\'s seam, ticket.assignee_deactivated)', function () {
    [$tenant] = ticketFixture('open');
    $gone = User::factory()->create();
    $gone->forceFill(['disabled_at' => Carbon\CarbonImmutable::parse('2026-08-01T00:00:00Z')])->save();

    $ticket = machineClosedTicket(anotherConversation($tenant->identifier(), 'gone'));
    Ticket::query()->whereKey($ticket->identifier())->update(['assignee_id' => $gone->identifier()]);

    /** @var Conversation $conv */
    $conv = Conversation::query()->findOrFail($ticket->conversation_id);
    $messageId = reopenCustomerMessage($tenant->identifier(), $conv->identifier());
    runReopenConsumer(reopenSeedEvent($conv, $messageId));

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Open)
        ->and($ticket->assignee_id)->toBeNull('a deactivated assignee must not silently keep reopened work');

    $handback = DB::table('outbox')->where('name', 'ticket.assignee_deactivated')->first();
    expect($handback)->not->toBeNull('Doc 11\'s re-route signal was not emitted');
    $payload = json_decode((string) $handback->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['previous_assignee_id'])->toBe($gone->identifier());
});

// ── FR-08: the staff door ─────────────────────────────────────────────────────

test('FR-08: staff reopen of a closed ticket needs tickets.reopen AND a reason; within the window it opens', function () {
    [$tenant] = ticketFixture('open');
    $ticket = machineClosedTicket(anotherConversation($tenant->identifier(), 'staff'));

    // No permission → refused by the edge.
    $unblessed = ticketActor(['tickets.status.change'], 'Fixture No-Reopen Role');
    expect(fn () => app(ReopenTicket::class)->reopenByStaff($ticket, $unblessed, 'a perfectly good reason'))
        ->toThrow(TransitionNotPermitted::class);

    // Permission but no reason → refused by the edge's reason rule.
    $blessed = ticketActor(['tickets.reopen'], 'Fixture Reopen Role');
    expect(fn () => app(ReopenTicket::class)->reopenByStaff($ticket, $blessed, '   '))
        ->toThrow(TransitionRefused::class, 'reason_required');

    // Both → open, reason on the audit record (SEC-03).
    $result = app(ReopenTicket::class)->reopenByStaff($ticket, $blessed, 'customer called back about the same fault');
    expect($result->ticket->status())->toBe(TicketStatus::Open)
        ->and($result->ticket->reopened_count)->toBe(1);

    $audit = DB::table('audit_log')->where('action_key', 'ticket.status_changed')->where('entity_id', $ticket->identifier())->orderByDesc('id')->first();
    $payload = json_decode((string) $audit->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['reason'])->toBe('customer called back about the same fault');
});

// ── The consumer's no-op edges ────────────────────────────────────────────────

test('the consumer does nothing when the conversation already holds an open-family ticket, and nothing when it holds no ticket at all', function () {
    // An open ticket: the message simply lands in it (FR-10).
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $messageId = reopenCustomerMessage($tenant->identifier(), $conversation->identifier());
    runReopenConsumer(reopenSeedEvent($conversation, $messageId));

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Open)
        ->and(Ticket::query()->where('conversation_id', $conversation->identifier())->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'ticket.reopened')->count())->toBe(0);

    // No ticket at all: creation on plain inbound traffic is Doc 09's decision.
    $bare = anotherConversation($tenant->identifier(), 'bare');
    $m2 = reopenCustomerMessage($tenant->identifier(), $bare->identifier());
    runReopenConsumer(reopenSeedEvent($bare, $m2));

    expect(Ticket::query()->where('conversation_id', $bare->identifier())->count())->toBe(0);
});

// ── The defensive wall, executed (owner order, step-6 commit 1) ──────────────
// A defensive path that has never run is a claim, not a guard: the consumer's
// decision ladder makes a wrong-status call unreachable in production, so this
// is the only place the LogicException wall ever fires.

test('reopenFromCustomerMessage on a wrong-status ticket throws the FR-08 LogicException and mutates nothing', function () {
    [$tenant, $conversation, $ticket] = ticketFixture('open');   // open: not resolved, not closed
    $messageId = reopenCustomerMessage($tenant->identifier(), $conversation->identifier());

    expect(fn () => app(ReopenTicket::class)->reopenFromCustomerMessage($ticket, $messageId))
        ->toThrow(LogicException::class, 'FR-08 reopens resolved or closed tickets only');

    $ticket->refresh();
    expect($ticket->status())->toBe(TicketStatus::Open, 'the refused call must not move the ticket')
        ->and($ticket->reopened_count)->toBe(0)
        ->and(DB::table('outbox')->where('name', 'ticket.reopened')->count())->toBe(0, 'the wall must not emit');
});

// ── R-03: DST dates cannot bend the window, because everything is UTC ────────

test('R-03: the window arithmetic is exact across DST-change dates — the session is UTC and 7 days is 168 hours on both sides of both switches', function () {
    // The precondition the whole property rests on (C-03: stored UTC, compared UTC).
    /** @var object{tz: string} $tz */
    $tz = DB::selectOne("SELECT current_setting('TimeZone') AS tz");
    expect(in_array($tz->tz, ['UTC', 'Etc/UTC'], true))->toBeTrue("the app session runs in '{$tz->tz}', not UTC — every interval below would be DST-warped");

    // Europe's 2026 switches: 29 Mar forward, 25 Oct back. In a local-time session
    // a "+7 days" spanning them is 167 h or 169 h; in UTC it is 168 h, always.
    /** @var object{spring: bool, autumn: bool, hours48: bool} $probe */
    $probe = DB::selectOne(<<<'SQL'
        SELECT ('2026-03-25 12:00:00+00'::timestamptz + make_interval(days => 7))
                 = '2026-04-01 12:00:00+00'::timestamptz AS spring,
               ('2026-10-20 12:00:00+00'::timestamptz + make_interval(days => 7))
                 = '2026-10-27 12:00:00+00'::timestamptz AS autumn,
               ('2026-10-24 00:00:00+00'::timestamptz + make_interval(hours => 48))
                 = '2026-10-26 00:00:00+00'::timestamptz AS hours48
    SQL);
    expect($probe->spring)->toBeTrue('a 7-day window spanning the spring-forward date is not 168 h')
        ->and($probe->autumn)->toBeTrue('a 7-day window spanning the fall-back date is not 168 h')
        ->and($probe->hours48)->toBeTrue('a 48 h auto-close spanning the fall-back date is not exactly 48 h');
});

test('R-03 end-to-end: a reopen window that spans the October DST switch is inclusive at exactly +7 d', function () {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    commitFixtures();

    try {
        $conv = anotherConversation($tenantId, 'dst');
        $ticket = machineClosedTicket($conv);

        // Pin BOTH sides across the 25 Oct 2026 switch: closed 20 Oct, reply at the
        // boundary instant 27 Oct. The message goes through the platform role
        // because its created_at must be a chosen date (R-19's grants forbid the
        // app choosing message time — which is exactly why the pin is trustworthy).
        $messageId = (string) Str::uuid7();
        DB::connection('pgsql_platform')->table('messages')->insert([
            'id' => $messageId,
            'tenant_id' => $tenantId,
            'conversation_id' => $conv->identifier(),
            'direction' => 'in',
            'author_type' => 'customer',
            'kind' => 'text',
            'body' => 'fixture DST boundary reply',
            'is_internal' => false,
            'created_at' => '2026-10-27 12:00:00+00',
        ]);
        DB::connection('pgsql_platform')->update(
            "UPDATE tickets SET closed_at = '2026-10-20 12:00:00+00' WHERE id = ?",
            [$ticket->identifier()],
        );

        runReopenConsumer(reopenSeedEvent($conv, $messageId));

        $ticket->refresh();
        expect($ticket->status())->toBe(TicketStatus::Open, 'the DST-spanning boundary instant must still be IN (168 h exactly)')
            ->and($ticket->reopened_count)->toBe(1);
    } finally {
        cleanupTenants([$tenantId]);
    }
});
