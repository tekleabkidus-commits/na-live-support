<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Inbox\ReadModels\SystemViews;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Jobs\Inbox\ListProjectorConsumer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 07 T-01 — the list projector (S07 step 2)
|--------------------------------------------------------------------------
|
| RECOMPUTE-FROM-TRUTH is the design under test: an event names tickets, every
| value is re-read from canonical rows, membership re-evaluated. C-09 falls
| out by construction — proven here by re-delivery (and sabotage #9 turns the
| one insert-shaped effect's dedupe off to show the proof bites). The
| kill/re-drive drill is ADR 0011's FS-02 obligation, on this consumer.
*/

// Shared helpers (inboxSeedEvent, runListProjector, listItemState, ticketRow)
// live in tests/Pest.php — Pest file functions are global, so one home only.

it('T-01: recomputes the row from truth — status, number, snippet, pinned stamps — and files an unassigned open ticket first-come (BR-04)', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    $messageId = reopenCustomerMessage($tenantId, $conversation->identifier());

    $eventId = inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $ticket->customer_id, 'source' => 'agent',
        'actor_type' => 'agent', 'actor_id' => null,
    ]);
    runListProjector($eventId);

    $row = DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe('new')
        ->and((int) $row->number)->toBe((int) $ticket->number)
        ->and($row->snippet)->toBe('fixture customer follow-up')
        ->and($row->snippet_kind)->toBe('text')
        ->and($row->ticket_created_at)->not->toBeNull()
        ->and($row->last_message_created_at)->not->toBeNull();

    // Unassigned membership, scored by the TICKET's DB-time birth (R-23 µs).
    $zsets = app(ViewZsets::class);
    expect($zsets->allIds('unassigned'))->toContain($ticket->identifier());

    $ticketCreated = (string) DB::table('tickets')->where('id', $ticket->identifier())->value('created_at');
    $score = Illuminate\Support\Facades\Redis::connection('volatile')
        ->zscore($zsets->key('unassigned'), $ticket->identifier());
    expect((int) $score)->toBe(ViewZsets::toMicros($ticketCreated), 'the unassigned score is not the ticket-birth µs — BR-04\'s first-come broke');
});

it('EC-11: a media-only last message stores the KIND as the placeholder key — never a raw storage key, never a body', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    DB::table('messages')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
        'conversation_id' => $conversation->identifier(),
        'direction' => 'in', 'author_type' => 'customer', 'kind' => 'photo',
        'body' => null, 'is_internal' => false,
        'media' => json_encode(['storage_key' => 't/fixture/orig.jpg'], JSON_THROW_ON_ERROR),
    ]);

    runListProjector(inboxSeedEvent('message.received', [
        'tenant_id' => $tenantId, 'message_id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $ticket->customer_id, 'kind' => 'photo',
    ]));

    $row = DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->first();
    expect($row->snippet_kind)->toBe('photo')
        ->and($row->snippet)->toBeNull('a media-only message leaked body text (or a storage key) into the snippet');
});

it('FR-05 my_open: assignment adds to the assignee\'s set; REASSIGNMENT removes from the old assignee\'s; resolve removes it', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $zsets = app(ViewZsets::class);

    $assignEvent = fn (?string $old, string $new): string => inboxSeedEvent('ticket.assigned', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'old_assignee_id' => $old, 'new_assignee_id' => $new, 'by' => $new,
    ]);

    DB::table('tickets')->where('id', $ticket->identifier())->update(['assignee_id' => $u1->identifier()]);
    runListProjector($assignEvent(null, $u1->identifier()));
    expect($zsets->allIds(SystemViews::userView('my_open', $u1->identifier())))->toContain($ticket->identifier())
        ->and($zsets->allIds('unassigned'))->not->toContain($ticket->identifier());

    DB::table('tickets')->where('id', $ticket->identifier())->update(['assignee_id' => $u2->identifier()]);
    runListProjector($assignEvent($u1->identifier(), $u2->identifier()));
    expect($zsets->allIds(SystemViews::userView('my_open', $u1->identifier())))->not->toContain($ticket->identifier(), 'the OLD assignee kept the ticket — reassignment must remove')
        ->and($zsets->allIds(SystemViews::userView('my_open', $u2->identifier())))->toContain($ticket->identifier());

    // Leaving the open family leaves the view. The status write is a direct
    // fixture write on the APP connection (the ticketFixture idiom — a
    // platform write here would land outside the harness transaction and
    // never be visible); production status writes stay the sole writer's.
    DB::table('tickets')->where('id', $ticket->identifier())->update(['status' => 'resolved']);
    runListProjector(inboxSeedEvent('ticket.status_changed', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'old_status' => 'open', 'new_status' => 'resolved',
        'actor_type' => 'agent', 'actor_id' => $u2->identifier(),
    ]));
    expect($zsets->allIds(SystemViews::userView('my_open', $u2->identifier())))->not->toContain($ticket->identifier());
});

it('C-09 by construction: the SAME event delivered twice recomputes identical state — row values, memberships, no duplicate effects', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    reopenCustomerMessage($tenantId, $conversation->identifier());
    $zsets = app(ViewZsets::class);

    $eventId = inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $ticket->customer_id, 'source' => 'agent',
        'actor_type' => 'agent', 'actor_id' => null,
    ]);

    runListProjector($eventId);
    $stateFirst = listItemState($ticket->identifier());
    $unassignedFirst = $zsets->allIds('unassigned');

    runListProjector($eventId); // at-least-once, arriving again

    expect(listItemState($ticket->identifier()))->toBe($stateFirst, 'redelivery changed the row — the projector is not recompute-from-truth')
        ->and($zsets->allIds('unassigned'))->toBe($unassignedFirst)
        ->and($zsets->count('unassigned'))->toBe(count(array_unique($unassignedFirst)));
});

it('A-32 mentions: note.mentioned mints ONE bell row (the dedupe index eats redelivery — sabotage #9 goes red here), membership until resolved', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $mentioned = User::factory()->create();
    $zsets = app(ViewZsets::class);

    $eventId = inboxSeedEvent('note.mentioned', [
        'tenant_id' => $tenantId, 'message_id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->identifier(),
        'author_id' => (string) Str::uuid7(), 'mentioned_user_id' => $mentioned->identifier(),
    ]);

    runListProjector($eventId);
    runListProjector($eventId); // C-09: the second delivery must be a database no-op

    $rows = DB::table('inbox_notifications')
        ->where('user_id', $mentioned->identifier())
        ->where('category', 'notify.inbox.mention')
        ->get();
    expect($rows)->toHaveCount(1, 'a redelivered note.mentioned minted a SECOND bell row — the C-09 dedupe is gone');

    $mentionsView = SystemViews::userView('mentions', $mentioned->identifier());
    expect($zsets->allIds($mentionsView))->toContain($ticket->identifier());

    // Resolution (A-32's door): latch + membership drop.
    app(ListProjector::class)->markMentionsResolved($mentioned->identifier(), $ticket->identifier());
    expect($zsets->allIds($mentionsView))->not->toContain($ticket->identifier())
        ->and(DB::table('inbox_notifications')->where('user_id', $mentioned->identifier())->whereNull('read_at')->count())->toBe(0);
});

it('FR-05 following: membership from ticket_followers truth; removal syncs the named user; activity rescores followers', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $follower = User::factory()->create();
    $zsets = app(ViewZsets::class);
    $view = SystemViews::userView('following', $follower->identifier());

    DB::table('ticket_followers')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
        'ticket_id' => $ticket->identifier(), 'user_id' => $follower->identifier(),
        'added_by' => $follower->identifier(),
    ]);
    runListProjector(inboxSeedEvent('ticket.follower_added', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'user_id' => $follower->identifier(), 'by' => $follower->identifier(),
    ]));
    expect($zsets->allIds($view))->toContain($ticket->identifier());

    DB::table('ticket_followers')->where('ticket_id', $ticket->identifier())->where('user_id', $follower->identifier())->delete();
    runListProjector(inboxSeedEvent('ticket.follower_removed', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'user_id' => $follower->identifier(), 'by' => $follower->identifier(),
    ]));
    expect($zsets->allIds($view))->not->toContain($ticket->identifier());
});

it('FR-05 expiring windows + rescue: Doc 05\'s window states and unreachable flags drive membership, cleared on recovery', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $zsets = app(ViewZsets::class);

    // Window enters the closing band (Doc 05 FR-22's event; state from truth).
    $expiry = DB::selectOne("SELECT (clock_timestamp() + interval '90 minutes') AS t")->t;
    DB::table('conversations')->where('id', $conversation->identifier())
        ->update(['reply_window_state' => 'closing', 'reply_window_expires_at' => $expiry]);
    runListProjector(inboxSeedEvent('telegram.window.closing', [
        'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'expires_at' => (string) $expiry,
    ]));
    expect($zsets->allIds('expiring_windows'))->toContain($ticket->identifier());

    // Reopened → out.
    DB::table('conversations')->where('id', $conversation->identifier())
        ->update(['reply_window_state' => 'open']);
    runListProjector(inboxSeedEvent('telegram.window.reopened', [
        'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(),
        'expires_at' => (string) $expiry, 'assignee_id' => null,
    ]));
    expect($zsets->allIds('expiring_windows'))->not->toContain($ticket->identifier());

    // Rescue: the Doc 05 writer's flag, synced on the connection transitions.
    DB::table('conversations')->where('id', $conversation->identifier())->update(['business_connection_id' => 'conn-fixture-1']);
    DB::table('tickets')->where('id', $ticket->identifier())->update(['channel_unreachable' => true]);
    runListProjector(inboxSeedEvent('telegram.connection.revoked', [
        'tenant_id' => $tenantId, 'bot_id' => (string) Str::uuid7(),
        'connection_id' => 'conn-fixture-1', 'account_user_id' => 900001111,
    ]));
    expect($zsets->allIds('rescue'))->toContain($ticket->identifier());

    DB::table('tickets')->where('id', $ticket->identifier())->update(['channel_unreachable' => false]);
    runListProjector(inboxSeedEvent('telegram.connection.active', [
        'tenant_id' => $tenantId, 'bot_id' => (string) Str::uuid7(),
        'connection_id' => 'conn-fixture-1', 'account_user_id' => 900001111,
    ]));
    expect($zsets->allIds('rescue'))->not->toContain($ticket->identifier());
});

it('FS-02/ADR 0011 drill: kill the consumer mid-upsert — no trace; re-drive converges; a third delivery changes nothing', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    reopenCustomerMessage($tenantId, $conversation->identifier());
    $zsets = app(ViewZsets::class);

    $eventId = inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $ticket->customer_id, 'source' => 'agent',
        'actor_type' => 'agent', 'actor_id' => null,
    ]);

    // ── THE KILL: mid-apply, BETWEEN the row upsert and the membership
    // patches (the real crash shape: a consumer dying half-applied). The
    // hook fires on the window-state read that precedes the ZSET writes —
    // QueryExecuted fires post-statement, so the upsert has landed and the
    // memberships have not.
    $armed = true;
    DB::listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$armed): void {
        if ($armed && str_contains($query->sql, 'reply_window_state')) {
            throw new RuntimeException('drill: projector died mid-apply');
        }
    });

    try {
        runListProjector($eventId);
        $this->fail('the kill hook did not fire — the drill proved nothing');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('drill: projector died');
    } finally {
        $armed = false;
    }

    // Half-applied, honestly: the row landed, the later memberships did not.
    expect(DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->exists())
        ->toBeTrue('the kill point is wrong — the upsert never ran, so the drill is not testing partial application')
        ->and($zsets->allIds('expiring_windows'))->not->toContain($ticket->identifier());

    // ── RE-DRIVE: same job, same event id — CONVERGES exactly (FS-02's
    // promise; recompute-from-truth makes the re-drive a full repair).
    runListProjector($eventId);
    expect(DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->exists())->toBeTrue()
        ->and($zsets->allIds('unassigned'))->toContain($ticket->identifier());

    // ── THIRD DELIVERY: nothing moves.
    $state = listItemState($ticket->identifier());
    runListProjector($eventId);
    expect(listItemState($ticket->identifier()))->toBe($state);
});

it('registers the projector for its 29 events in the ConsumerRegistry (ADR 0011 — an entry, never a relay edit)', function (): void {
    $expected = [
        'message.received', 'message.sent', 'note.mentioned',
        'ticket.created', 'ticket.status_changed', 'ticket.assigned', 'ticket.assignee_deactivated',
        'ticket.priority_changed', 'ticket.subject_changed', 'ticket.tagged', 'ticket.untagged',
        'ticket.transferred', 'ticket.escalated', 'ticket.deescalated', 'ticket.merged',
        'ticket.unmerged', 'ticket.split', 'ticket.spam_marked', 'ticket.spam_unmarked',
        'ticket.reopened', 'ticket.resolved', 'ticket.closed', 'ticket.follower_added',
        'ticket.follower_removed', 'telegram.window.closing', 'telegram.window.expired',
        'telegram.window.reopened', 'telegram.connection.revoked', 'telegram.connection.active',
    ];

    expect(count($expected))->toBe(29);
    foreach ($expected as $event) {
        // in_array, NOT toContain($class, $message) — the S22 variadic-needle
        // trap, dodged by name (again).
        expect(in_array(ListProjectorConsumer::class, App\Support\Events\ConsumerRegistry::consumersFor($event), true))
            ->toBeTrue("{$event} lost its projector registration");
    }
});
