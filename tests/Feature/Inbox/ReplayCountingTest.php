<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Inbox\ReadModels\SystemViews;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| T-01/T-03 — the counting contract is proven against EVENT REPLAY, and
| NFR-07's checker is a deliverable (S07 step 2; inherited row 6's exact words:
| "replay a recorded event stream into a cold projector and assert ZCARD
| equals the direct scoped query")
|--------------------------------------------------------------------------
*/

it('replay-counting: a recorded stream into a COLD projector lands every view at ZCARD == direct query, member-for-member — then the NFR-07 checker agrees, and a seeded drift trips it', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $zsets = app(ViewZsets::class);
    $stream = [];

    // ── THE SCENE: eight tickets across every membership shape, built through
    //    the fixtures, with the event stream RECORDED as it happens.
    $record = function (string $name, array $payload) use (&$stream): void {
        $stream[] = inboxSeedEvent($name, $payload);
    };
    $created = fn (App\Models\Ticket $t, App\Models\Conversation $c) => [
        'tenant_id' => $t->tenant_id, 'ticket_id' => $t->identifier(),
        'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ];

    // 1: the fixture's unassigned new ticket.
    reopenCustomerMessage($tenantId, $conversation->identifier());
    $record('ticket.created', $created($ticket, $conversation));

    // 2+3: two open tickets assigned to u1.
    foreach (range(1, 2) as $i) {
        $c = anotherConversation($tenantId, "u1-{$i}");
        [$t] = inboxTicketRow($tenantId, $c, 'open', ['assignee_id' => $u1->identifier()]);
        $record('ticket.created', $created($t, $c));
    }

    // 4: one open ticket assigned to u2, which u1 FOLLOWS.
    $c4 = anotherConversation($tenantId, 'u2');
    [$t4] = inboxTicketRow($tenantId, $c4, 'open', ['assignee_id' => $u2->identifier()]);
    $record('ticket.created', $created($t4, $c4));
    DB::table('ticket_followers')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
        'ticket_id' => $t4->identifier(), 'user_id' => $u1->identifier(), 'added_by' => $u2->identifier(),
    ]);
    $record('ticket.follower_added', ['tenant_id' => $tenantId, 'ticket_id' => $t4->identifier(), 'user_id' => $u1->identifier(), 'by' => $u2->identifier()]);

    // 5: a second unassigned open ticket.
    $c5 = anotherConversation($tenantId, 'unassigned2');
    [$t5] = inboxTicketRow($tenantId, $c5, 'open');
    $record('ticket.created', $created($t5, $c5));

    // 6: a RESOLVED ticket — in NO open-family view.
    $c6 = anotherConversation($tenantId, 'resolved');
    [$t6] = inboxTicketRow($tenantId, $c6, 'resolved');
    $record('ticket.created', $created($t6, $c6));

    // 7: a closing-window ticket (Doc 05 FR-22).
    $c7 = anotherConversation($tenantId, 'window');
    [$t7] = inboxTicketRow($tenantId, $c7, 'open');
    $expiry = DB::selectOne("SELECT (clock_timestamp() + interval '1 hour') AS t")->t;
    DB::table('conversations')->where('id', $c7->identifier())
        ->update(['reply_window_state' => 'closing', 'reply_window_expires_at' => $expiry]);
    $record('ticket.created', $created($t7, $c7));
    $record('telegram.window.closing', ['tenant_id' => $tenantId, 'conversation_id' => $c7->identifier(), 'expires_at' => (string) $expiry]);

    // 8: an unreachable ticket (Doc 05 FR-10's rescue data).
    $c8 = anotherConversation($tenantId, 'rescue');
    [$t8] = inboxTicketRow($tenantId, $c8, 'open');
    DB::table('conversations')->where('id', $c8->identifier())->update(['business_connection_id' => 'conn-replay-1']);
    DB::table('tickets')->where('id', $t8->identifier())->update(['channel_unreachable' => true]);
    $record('ticket.created', $created($t8, $c8));
    $record('telegram.connection.revoked', ['tenant_id' => $tenantId, 'bot_id' => (string) Str::uuid7(), 'connection_id' => 'conn-replay-1', 'account_user_id' => 900002222]);

    // A mention for u2 on ticket 1.
    $record('note.mentioned', ['tenant_id' => $tenantId, 'message_id' => (string) Str::uuid7(), 'conversation_id' => $conversation->identifier(), 'author_id' => $u1->identifier(), 'mentioned_user_id' => $u2->identifier()]);

    // ── COLD REPLAY: the tenant is fresh so every view key starts empty —
    //    asserted, not assumed.
    expect($zsets->count('unassigned'))->toBe(0, 'the projector was not cold');
    foreach ($stream as $eventId) {
        runListProjector($eventId);
    }

    // SLA seam: fixture-fed AFTER rows exist (Amendment rider (ii)), then one
    // more recompute so membership sees it.
    $slaDue = DB::selectOne("SELECT (clock_timestamp() + interval '30 minutes') AS t")->t;
    DB::table('ticket_list_items')->where('ticket_id', $t5->identifier())
        ->update(['sla_state' => 'warned', 'sla_due_at' => $slaDue]);
    app(ListProjector::class)->recomputeTicket($t5->identifier());

    // ── THE COUNTING CONTRACT, view by view: ZCARD == direct scoped query,
    //    and the member SETS are identical (a count matching by accident of
    //    offsetting errors would still fail here).
    $assertView = function (string $viewKey, string $view, ?string $userId) use ($zsets): void {
        $truth = SystemViews::directQuery($view, $userId)->pluck('id')->all();
        $materialized = $zsets->allIds($viewKey);
        sort($truth);
        sort($materialized);

        expect($materialized)->toBe($truth, "{$viewKey}: members diverge from the direct query")
            ->and($zsets->count($viewKey))->toBe(count($truth), "{$viewKey}: ZCARD diverges from the direct count");
    };

    $assertView('unassigned', 'unassigned', null);
    $assertView('sla_at_risk', 'sla_at_risk', null);
    $assertView('expiring_windows', 'expiring_windows', null);
    $assertView('rescue', 'rescue', null);
    $assertView(SystemViews::userView('my_open', $u1->identifier()), 'my_open', $u1->identifier());
    $assertView(SystemViews::userView('my_open', $u2->identifier()), 'my_open', $u2->identifier());
    $assertView(SystemViews::userView('following', $u1->identifier()), 'following', $u1->identifier());
    $assertView(SystemViews::userView('mentions', $u2->identifier()), 'mentions', $u2->identifier());

    // Spot the shape too, not only the equality: FOUR unassigned open tickets
    // exist (the fixture's, #5, and the window/rescue tickets #7/#8 — both
    // open with no assignee), 2 for u1, 1 SLA.
    expect($zsets->count('unassigned'))->toBe(4)
        ->and($zsets->count(SystemViews::userView('my_open', $u1->identifier())))->toBe(2)
        ->and($zsets->count('sla_at_risk'))->toBe(1)
        ->and($zsets->count('expiring_windows'))->toBe(1)
        ->and($zsets->count('rescue'))->toBe(1);

    // ── NFR-07's checker: green on the consistent state…
    $this->artisan('inbox:consistency-check', ['--tenant' => $tenantId])->assertExitCode(0);

    // …and a SEEDED DRIFT trips it (the positive control the checker's own
    // greenness needs — S19 lesson #7).
    $bogus = (string) Str::uuid7();
    Illuminate\Support\Facades\Redis::connection('volatile')
        ->zadd($zsets->key('unassigned'), 1_000_000, $bogus);
    $this->artisan('inbox:consistency-check', ['--tenant' => $tenantId])->assertExitCode(1);
    Illuminate\Support\Facades\Redis::connection('volatile')
        ->zrem($zsets->key('unassigned'), $bogus);
    $this->artisan('inbox:consistency-check', ['--tenant' => $tenantId])->assertExitCode(0);
});
