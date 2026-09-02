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

it('S09 POST-CLOSE RULING — tombstones never serve the list: a DELETED last public message falls back to the prior message\'s snippet AND language, never the deleted body; with no public message left, the empty-snippet contract holds', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    $older = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $older, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(),
        'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text',
        'body' => 'የመጀመሪያው መልእክት', 'language' => 'am', 'is_internal' => false,
    ]);
    $newer = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $newer, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(),
        'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text',
        'body' => 'the newer english message', 'language' => 'en', 'is_internal' => false,
    ]);

    $projector = app(ListProjector::class);
    $projector->recomputeTicket($ticket->identifier());
    $row = DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->first();
    expect($row->snippet)->toBe('the newer english message')
        ->and($row->language)->toBe('en');

    // Tombstone the newest: the thread renders it as a placeholder, so the
    // list must not keep saying its body — the PRIOR public message serves.
    DB::table('messages')->where('id', $newer)->update(['deleted_at' => DB::raw('clock_timestamp()')]);
    $projector->recomputeTicket($ticket->identifier());
    $row = DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->first();
    expect($row->snippet)->toBe('የመጀመሪያው መልእክት', 'the deleted body kept serving as the snippet — the list leaks what the thread hides')
        ->and($row->language)->toBe('am', 'the FR-09 language chip still follows the deleted message');

    // Tombstone the last one too: the existing empty-snippet contract, not
    // an error and not a resurrected body.
    DB::table('messages')->where('id', $older)->update(['deleted_at' => DB::raw('clock_timestamp()')]);
    $projector->recomputeTicket($ticket->identifier());
    $row = DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->first();
    expect($row->snippet)->toBe('')
        ->and($row->language)->toBeNull()
        ->and($row->last_message_created_at)->toBeNull();
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

it('registers the projector for its 33 events in the ConsumerRegistry (ADR 0011 — an entry, never a relay edit)', function (): void {
    $expected = [
        'message.received', 'message.sent', 'note.mentioned',
        'ticket.created', 'ticket.status_changed', 'ticket.assigned', 'ticket.assignee_deactivated',
        'ticket.priority_changed', 'ticket.subject_changed', 'ticket.tagged', 'ticket.untagged',
        'ticket.transferred', 'ticket.escalated', 'ticket.deescalated', 'ticket.merged',
        'ticket.unmerged', 'ticket.split', 'ticket.spam_marked', 'ticket.spam_unmarked',
        'ticket.reopened', 'ticket.resolved', 'ticket.closed', 'ticket.follower_added',
        'ticket.follower_removed', 'telegram.window.closing', 'telegram.window.expired',
        'telegram.window.reopened', 'telegram.connection.revoked', 'telegram.connection.active',
        // S11 step 9 (P-C2): the two rescue arms — Doc 11's flags populate the Rescue view.
        'rescue.flagged', 'rescue.auto_returned',
        // S12 batch D (P-CHIP): the two SLA flip events — the row re-reads Chips' truth.
        'sla.warned', 'sla.breached',
    ];

    expect(count($expected))->toBe(33);
    foreach ($expected as $event) {
        // in_array, NOT toContain($class, $message) — the S22 variadic-needle
        // trap, dodged by name (again).
        expect(in_array(ListProjectorConsumer::class, App\Support\Events\ConsumerRegistry::consumersFor($event), true))
            ->toBeTrue("{$event} lost its projector registration");
    }
});

/*
|--------------------------------------------------------------------------
| S07 step 12 — FR-26's bell fanout (per-user rows from EXISTING events)
|--------------------------------------------------------------------------
*/

it('FR-26 fanout: ticket.assigned mints the NEW assignee\'s bell row — never a self-row, never on unassign, and redelivery is a no-op (C-09)', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $assignee = User::factory()->create();
    $assigner = User::factory()->create();

    $eventId = inboxSeedEvent('ticket.assigned', [
        'tenant_id' => $tenant->identifier(), 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'old_assignee_id' => null, 'new_assignee_id' => $assignee->identifier(),
        'by' => $assigner->identifier(),
    ]);
    runListProjector($eventId);
    runListProjector($eventId); // C-09

    $rows = DB::table('inbox_notifications')->where('category', 'notify.inbox.assignment')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->user_id)->toBe($assignee->identifier())
        ->and($rows[0]->ticket_id)->toBe($ticket->identifier());

    // Self-assign mints nothing; unassign (null new) mints nothing.
    runListProjector(inboxSeedEvent('ticket.assigned', [
        'tenant_id' => $tenant->identifier(), 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'old_assignee_id' => null, 'new_assignee_id' => $assigner->identifier(), 'by' => $assigner->identifier(),
    ]));
    runListProjector(inboxSeedEvent('ticket.assigned', [
        'tenant_id' => $tenant->identifier(), 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'old_assignee_id' => $assigner->identifier(), 'new_assignee_id' => null, 'by' => $assigner->identifier(),
    ]));
    expect(DB::table('inbox_notifications')->where('category', 'notify.inbox.assignment')->count())->toBe(1);
});

it('FR-26 fanout: escalation rows to the TARGET (escalation-to-me) and Doc 06 FR-18 follower rows minus actor and target; status changes and public replies fan to followers minus the actor', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $follower = User::factory()->create();
    foreach ([$actor, $target, $follower] as $user) {
        DB::table('ticket_followers')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->identifier(),
            'ticket_id' => $ticket->identifier(), 'user_id' => $user->identifier(),
        ]);
    }

    runListProjector(inboxSeedEvent('ticket.escalated', [
        'tenant_id' => $tenant->identifier(), 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'path' => 'user',
        'target_id' => $target->identifier(), 'reason' => 'needs a supervisor decision',
        'actor_type' => 'staff', 'actor_id' => $actor->identifier(),
    ]));

    expect(DB::table('inbox_notifications')->where('category', 'notify.inbox.escalation')->pluck('user_id')->all())
        ->toBe([$target->identifier()])
        ->and(DB::table('inbox_notifications')->where('category', 'notify.inbox.follower')->pluck('user_id')->all())
        ->toBe([$follower->identifier()]); // never the actor, never the target twice

    // Q7 row posture: the escalation REASON text never lands in the row.
    /** @var object{payload: string} $row */
    $row = DB::table('inbox_notifications')->where('category', 'notify.inbox.escalation')->first(['payload']);
    expect($row->payload)->not->toContain('supervisor decision');

    runListProjector(inboxSeedEvent('ticket.status_changed', [
        'tenant_id' => $tenant->identifier(), 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(),
        'old_status' => 'open', 'new_status' => 'pending_customer',
        'actor_type' => 'staff', 'actor_id' => $actor->identifier(),
    ]));
    $statusRows = DB::table('inbox_notifications')->where('category', 'notify.inbox.follower')
        ->whereRaw("payload->>'kind' = 'status'")->pluck('user_id')->all();
    expect($statusRows)->toContain($follower->identifier())->toContain($target->identifier())
        ->not->toContain($actor->identifier());

    // Public reply fans to followers minus the author; an internal NOTE mints nothing.
    $replyId = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $replyId, 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversation->identifier(),
        'direction' => 'out', 'author_type' => 'agent', 'author_id' => $actor->identifier(),
        'kind' => 'text', 'body' => 'public reply', 'is_internal' => false, 'delivery_state' => 'sent',
    ]);
    runListProjector(inboxSeedEvent('message.sent', [
        'tenant_id' => $tenant->identifier(), 'message_id' => $replyId, 'conversation_id' => $conversation->identifier(),
    ]));
    $replyRows = DB::table('inbox_notifications')->whereRaw("payload->>'kind' = 'reply'")->pluck('user_id')->all();
    expect($replyRows)->toContain($follower->identifier())->not->toContain($actor->identifier());

    $noteId = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $noteId, 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversation->identifier(),
        'direction' => 'out', 'author_type' => 'agent', 'author_id' => $actor->identifier(),
        'kind' => 'note', 'body' => 'internal note', 'is_internal' => true, 'delivery_state' => 'sent',
    ]);
    $before = DB::table('inbox_notifications')->count();
    runListProjector(inboxSeedEvent('message.sent', [
        'tenant_id' => $tenant->identifier(), 'message_id' => $noteId, 'conversation_id' => $conversation->identifier(),
    ]));
    expect(DB::table('inbox_notifications')->count())->toBe($before, 'an internal note fanned out to followers — notes are the mention channel only');
});

it('FR-26 fanout: window-closing rows to the open ticket\'s assignee; bulk-completed rows to the requester with COUNTS only', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $assignee = User::factory()->create();
    DB::table('tickets')->where('id', $ticket->identifier())->update(['assignee_id' => $assignee->identifier()]);
    DB::table('conversations')->where('id', $conversation->identifier())->update(['open_ticket_id' => $ticket->identifier()]);

    runListProjector(inboxSeedEvent('telegram.window.closing', [
        'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversation->identifier(),
        'expires_at' => '2026-08-11T12:00:00Z',
    ]));
    $rows = DB::table('inbox_notifications')->where('category', 'notify.inbox.window_closing')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->user_id)->toBe($assignee->identifier())
        ->and($rows[0]->ticket_id)->toBe($ticket->identifier());

    runListProjector(inboxSeedEvent('ticket.bulk_completed', [
        'tenant_id' => $tenant->identifier(), 'bulk_job_id' => (string) Str::uuid7(),
        'action' => 'assign', 'ok' => 18, 'failed' => [['ticket_id' => 'x', 'reason' => 'y'], ['ticket_id' => 'z', 'reason' => 'w']],
        'requested_by' => $assignee->identifier(),
    ]));
    /** @var object{payload: string} $bulk */
    $bulk = DB::table('inbox_notifications')->where('category', 'notify.inbox.bulk_completed')->first(['payload']);
    /** @var array<string, mixed> $payload */
    $payload = json_decode($bulk->payload, true, 8, JSON_THROW_ON_ERROR);
    expect($payload['ok'])->toBe(18)->and($payload['failed'])->toBe(2);
});
