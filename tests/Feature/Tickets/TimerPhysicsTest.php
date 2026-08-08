<?php

declare(strict_types=1);

use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Tickets\Timers\TicketTimers;
use App\Domain\Tickets\Timers\TicketTimerSweep;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 T-03 / FS-01 / SS-03 — timer physics (step 5)
|--------------------------------------------------------------------------
|
| The division of authority under fire: the ZSET is only ever a candidate list, the
| columns are the truth, and every failure mode must land on the safe side of that
| line — FS-01's "worst case auto-closes delayed, never premature", proven by
| inflicting each loss and watching which side it lands on.
|
*/

/** A resolved-through-the-machine ticket in an existing conversation (real stamps, real arm). */
function timerResolvedTicket(Conversation $conversation): Ticket
{
    $ticketId = (string) Str::uuid7();

    DB::table('tickets')->insert([
        'id' => $ticketId,
        'tenant_id' => $conversation->getAttribute('tenant_id'),
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $conversation->customer_id,
        'number' => random_int(1, 2_000_000_000),
        'status' => 'new',
        'priority' => 'normal',
        'source' => 'agent',
    ]);

    /** @var Ticket $ticket */
    $ticket = Ticket::query()->findOrFail($ticketId);
    app(ConversationRepository::class)->claimOpenTicket($conversation, $ticketId);

    $service = app(TicketTransitionService::class);
    foreach (['open', 'resolved'] as $status) {
        $service->transition($ticket, TicketStatus::from($status), TransitionContext::system('timer fixture walk'));
        $ticket->refresh();
    }

    return $ticket;
}

beforeEach(function (): void {
    Redis::connection('durable')->del(TicketTimers::ZSET);
});

test('FS-01: a lost ZSET only DELAYS auto-closes; rebuild restores candidates from column truth at their exact deadlines, and nothing closes early', function () {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    $due = timerResolvedTicket(anotherConversation($tenantId, 'fs01-due'));
    $future = timerResolvedTicket(anotherConversation($tenantId, 'fs01-future'));

    commitFixtures();

    try {
        DB::connection('pgsql_platform')->update(
            "UPDATE tickets SET resolved_at = resolved_at - interval '49 hours', auto_close_at = auto_close_at - interval '49 hours' WHERE id = ?",
            [$due->identifier()],
        );
        $due->refresh();
        $future->refresh();

        // THE LOSS. Every candidate gone; the armed columns untouched.
        Redis::connection('durable')->del(TicketTimers::ZSET);

        $blind = app(TicketTimerSweep::class)->sweep();
        $due->refresh();
        expect(array_sum($blind))->toBe(0, 'a sweep with no candidates must touch nothing')
            ->and($due->status())->toBe(TicketStatus::Resolved, 'FS-01: the due ticket waits — DELAYED is the promised failure mode');

        // THE RECOVERY. Exactly the armed set, at exactly the column deadlines.
        // (Platform-scope, so no tenant may be bound — and asPlatformWorker leaves
        // the context CLEARED, so everything after rebinds through asTenant.)
        $restored = asPlatformWorker(fn (): int => app(TicketTimers::class)->rebuild());
        expect($restored)->toBe(2);

        expect(ticketTimerScore($tenantId.'|'.$due->identifier()))->toBe((int) $due->auto_close_at->format('Uu'), 'rebuild must restore the EXACT column deadline')
            ->and(ticketTimerScore($tenantId.'|'.$future->identifier()))->toBe((int) $future->auto_close_at->format('Uu'));

        // Post-rebuild sweep: the due one closes, the future one is NOT EVEN VISITED
        // (its score is ahead of now) — never premature, by construction.
        asTenant($tenant, function () use ($due, $future): void {
            $after = app(TicketTimerSweep::class)->sweep();
            $due->refresh();
            $future->refresh();
            expect($after['closed'])->toBe(1)
                ->and($due->status())->toBe(TicketStatus::Closed)
                ->and($future->status())->toBe(TicketStatus::Resolved, 'rebuild + sweep closed a timer that was not due')
                ->and($future->auto_close_at)->not->toBeNull();
        });
    } finally {
        cleanupTenants([$tenantId]);
    }
});

test('a stale candidate — the ticket long since moved on — is dropped by the DB re-check and closes nothing', function () {
    [$tenant, , $ticket] = ticketFixture('open');   // open: was never armed
    $member = $tenant->identifier().'|'.$ticket->identifier();

    app(TicketTimers::class)->add($member, 1);      // due since the epoch, says Redis

    $result = app(TicketTimerSweep::class)->sweep();

    $ticket->refresh();
    expect($result['dropped'])->toBe(1)
        ->and($result['closed'])->toBe(0)
        ->and($ticket->status())->toBe(TicketStatus::Open, 'a stale candidate must never close a live ticket')
        ->and(ticketTimerScore($member))->toBeNull('the stale member must be reaped');
});

test('a candidate whose score lies about dueness is DEFERRED: the column is the truth and the member is re-scored to it', function () {
    [$tenant, $conversation] = ticketFixture('open');
    $ticket = timerResolvedTicket(anotherConversation($tenant->identifier(), 'defer'));
    $member = $tenant->identifier().'|'.$ticket->identifier();

    // Lie to Redis: due since the epoch. The column still says +48 h.
    app(TicketTimers::class)->add($member, 1);

    $result = app(TicketTimerSweep::class)->sweep();

    $ticket->refresh();
    expect($result['deferred'])->toBe(1)
        ->and($ticket->status())->toBe(TicketStatus::Resolved, 'a premature close is the one FS-01 forbids outright')
        ->and(ticketTimerScore($member))
        ->toBe((int) $ticket->auto_close_at->format('Uu'), 'the member must be re-scored to the column deadline');
});

test('SS-03: changing tickets.autoclose.hours applies to FUTURE resolutions only — armed timers keep their value', function () {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    $armedAt48 = timerResolvedTicket(anotherConversation($tenantId, 'ss03-a'));
    $memberA = $tenantId.'|'.$armedAt48->identifier();
    $scoreBefore = ticketTimerScore($memberA);
    expect($scoreBefore)->not->toBeNull('fixture failed: the resolve did not arm a candidate');

    // US-07's operator move: 48 h → 72 h.
    app(SettingsRepository::class)->set('tickets.autoclose.hours', 72);

    // The ARMED timer did not move — column or candidate.
    /** @var object{exact: bool} $a */
    $a = DB::selectOne(
        'SELECT (auto_close_at = resolved_at + make_interval(hours => 48)) AS exact FROM tickets WHERE id = ?',
        [$armedAt48->identifier()],
    );
    expect($a->exact)->toBeTrue('SS-03: an armed timer was re-targeted by a settings change')
        ->and(ticketTimerScore($memberA))->toBe($scoreBefore);

    // A FUTURE resolution takes the new value.
    $armedAt72 = timerResolvedTicket(anotherConversation($tenantId, 'ss03-b'));
    /** @var object{exact: bool} $b */
    $b = DB::selectOne(
        'SELECT (auto_close_at = resolved_at + make_interval(hours => 72)) AS exact FROM tickets WHERE id = ?',
        [$armedAt72->identifier()],
    );
    expect($b->exact)->toBeTrue('a post-change resolution must arm at the new 72 h');

    // "…and the settings page says exactly that": the copy exists, in both launch
    // languages, ready for the settings surface to render (C-08).
    expect(Lang::hasForLocale('tickets.settings.autoclose_change_note', 'en'))->toBeTrue()
        ->and(Lang::hasForLocale('tickets.settings.autoclose_change_note', 'am'))->toBeTrue();
});

test('BR-07: the sweep HOLDS an unreachable customer\'s due timer — refused by the machine, candidate dropped, armed state left visible', function () {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $ticket = timerResolvedTicket(anotherConversation($tenantId, 'br07'));

    Ticket::query()->whereKey($ticket->identifier())->update(['channel_unreachable' => true]);

    commitFixtures();

    try {
        DB::connection('pgsql_platform')->update(
            "UPDATE tickets SET resolved_at = resolved_at - interval '49 hours', auto_close_at = auto_close_at - interval '49 hours' WHERE id = ?",
            [$ticket->identifier()],
        );
        $ticket->refresh();
        app(TicketTimers::class)->add($tenantId.'|'.$ticket->identifier(), (int) $ticket->auto_close_at->format('Uu'));

        $result = app(TicketTimerSweep::class)->sweep();

        $ticket->refresh();
        expect($result['held'])->toBe(1)
            ->and($result['closed'])->toBe(0)
            ->and($ticket->status())->toBe(TicketStatus::Resolved, 'BR-07: unreachable never auto-closes — a human decides')
            ->and($ticket->auto_close_at)->not->toBeNull('the armed state stays visible for Doc 12\'s pause policy (EC-10)')
            ->and(ticketTimerScore($tenantId.'|'.$ticket->identifier()))->toBeNull('the candidate must not grind every sweep forever');
    } finally {
        cleanupTenants([$tenantId]);
    }
});
