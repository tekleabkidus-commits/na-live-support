<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Inbox\ReadModels\ReadMarkers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| S07 step 9 — FR-12's cursor endpoint (C-05), the SCHEMA AMENDMENT 1
| rider (i) byte-equality proof through the REAL marker writer, the
| per-viewer overlay, FR-10's sort override, and Doc 05 EC-04's group
| flags (the closing row's list half).
|--------------------------------------------------------------------------
*/

function listScene(int $tickets, string $status = 'open'): array
{
    [$tenant, $conversation, $ticket] = ticketFixture($status);
    $tenantId = $tenant->identifier();
    $rows = [[$ticket, $conversation]];

    runListProjector(inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $ticket->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]));

    for ($i = 2; $i <= $tickets; $i++) {
        $c = anotherConversation($tenantId, "lst-{$i}");
        [$t] = inboxTicketRow($tenantId, $c, $status);
        $rows[] = [$t, $c];
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    }

    return [$tenant, $tenantId, $rows];
}

function inboundListMessage(string $tenantId, string $conversationId, string $body = 'fixture inbound'): string
{
    $id = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
        'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text',
        'body' => $body, 'is_internal' => false,
    ]);

    return $id;
}

it('SCHEMA AMENDMENT 1 RIDER (i) — DISCHARGED HERE, CITED: last_read_at is a BYTE-EQUAL copy of the message\'s own DB-time created_at, through the REAL marker writer (both sides read back as text, compared verbatim)', function (): void {
    [$tenant, $tenantId, $rows] = listScene(1);
    [$ticket, $conversation] = $rows[0];
    $actor = inboxScopedActor('All', 'S09 Marker Writer');
    $messageId = inboundListMessage($tenantId, $conversation->identifier());

    app(ReadMarkers::class)->markRead($actor, $conversation->identifier(), $messageId);

    /** @var object{source: string, copy: string} $pair */
    $pair = DB::selectOne(
        'SELECT m.created_at::text AS source, r.last_read_at::text AS copy
         FROM messages m, inbox_read_markers r
         WHERE m.id = ? AND r.user_id = ? AND r.conversation_id = ?',
        [$messageId, $actor->identifier(), $conversation->identifier()],
    );

    // The rider's exact bar: BYTE equality, not semantic equality — the copy
    // never round-trips through PHP (INSERT … SELECT, the OutboxRelay idiom).
    expect($pair->copy)->toBe($pair->source, 'last_read_at is not a byte-copy of the message stamp (rider (i) violated)');
    expect(DB::table('inbox_read_markers')->where('user_id', $actor->identifier())->value('last_read_message_id'))
        ->toBe($messageId, 'the provenance pointer does not name the source message');
});

it('the marker never moves BACKWARD (LWW guarded in message-stamp space): marking an older message read after a newer one is a silent no-op', function (): void {
    [$tenant, $tenantId, $rows] = listScene(1);
    [, $conversation] = $rows[0];
    $actor = inboxScopedActor('All', 'S09 Marker LWW');
    $older = inboundListMessage($tenantId, $conversation->identifier(), 'older');
    $newer = inboundListMessage($tenantId, $conversation->identifier(), 'newer');

    $markers = app(ReadMarkers::class);
    $markers->markRead($actor, $conversation->identifier(), $newer);
    $markers->markRead($actor, $conversation->identifier(), $older); // no-op, not an error

    expect(DB::table('inbox_read_markers')->where('user_id', $actor->identifier())->value('last_read_message_id'))
        ->toBe($newer, 'a backward mark moved the unread boundary');
});

it('the markRead HTTP door rides views.manage (R-26 AMENDMENT, the FOURTH sibling — cited, not re-ruled): a view-only reader is refused 403 with NO marker row; the workspace actor lands it; a wrong message answers the 404 pattern', function (): void {
    [$tenant, $tenantId, $rows] = listScene(1);
    [, $conversation] = $rows[0];
    activateTenant($tenant);
    $messageId = inboundListMessage($tenantId, $conversation->identifier());

    // BOTH principals minted BEFORE the first HTTP call — request teardown
    // unbinds the harness context (the OutboxChaos idiom).
    $reader = grantPermissions(App\Models\User::factory()->create(), ['tickets.view', 'conversations.view'], 'S09 RO Reader Role');
    $actor = inboxScopedActor('All', 'S09 Marker HTTP');
    $this->actingAs($reader, 'staff')
        ->postJson('https://localhost/inbox/read', ['conversation_id' => $conversation->identifier(), 'message_id' => $messageId])
        ->assertStatus(403);
    asTenant($tenant, function (): void {
        expect(DB::table('inbox_read_markers')->count())->toBe(0, 'the refused door still wrote a marker');
    });

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/read', ['conversation_id' => $conversation->identifier(), 'message_id' => $messageId])
        ->assertOk();

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/read', ['conversation_id' => $conversation->identifier(), 'message_id' => (string) Str::uuid7()])
        ->assertStatus(404);
});

it('FR-12/C-05: cursor pages are disjoint, exhaustive and ordered (unassigned = created asc, BR-04 first-come); total = the viewer\'s ZCARD; a malformed cursor restarts from the top', function (): void {
    [$tenant, $tenantId] = listScene(7);
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S09 Cursor');

    $seen = [];
    $cursor = null;
    for ($page = 1; $page <= 3; $page++) {
        $url = 'https://localhost/inbox/list?view=unassigned&limit=3'.($cursor !== null ? '&cursor='.urlencode($cursor) : '');
        $response = $this->actingAs($actor, 'staff')->getJson($url);
        $response->assertOk();
        expect($response->json('total'))->toBe(7, 'total is not the viewer ZCARD');

        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json('rows');
        expect(count($rows))->toBe($page === 3 ? 1 : 3);

        $stamps = array_map(fn (array $row): string => (string) $row['ticket_created_at'], $rows);
        $sorted = $stamps;
        sort($sorted);
        expect($stamps)->toBe($sorted, 'page not in created-asc order (BR-04)');

        foreach ($rows as $row) {
            expect(in_array($row['ticket_id'], $seen, true))->toBeFalse('cursor pages overlap');
            $seen[] = $row['ticket_id'];
        }
        $cursor = $response->json('next_cursor');
    }
    expect($cursor)->toBeNull('the exhausted list still offers a cursor');
    expect(count($seen))->toBe(7, 'cursor pages are not exhaustive');

    $this->actingAs($actor, 'staff')
        ->getJson('https://localhost/inbox/list?view=unassigned&limit=3&cursor=%%%garbage')
        ->assertOk(); // C-05's honest fallback: restart, never 500
});

it('the per-viewer overlay: unread counts from the marker boundary (all-unread with no marker; zero after markRead), has_draft from the viewer\'s live draft — per viewer, never shared', function (): void {
    [$tenant, $tenantId, $rows] = listScene(2);
    [, $conversationA] = $rows[0];
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S09 Overlay');

    $first = inboundListMessage($tenantId, $conversationA->identifier(), 'one');
    inboundListMessage($tenantId, $conversationA->identifier(), 'two');

    DB::table('message_drafts')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
        'user_id' => $actor->identifier(), 'conversation_id' => $conversationA->identifier(),
        'body' => 'fixture draft',
    ]);

    $colleague = inboxScopedActor('All', 'S09 Overlay Colleague'); // pre-HTTP, same idiom
    $response = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/list?view=unassigned');
    $response->assertOk();
    /** @var array<string, array<string, mixed>> $byConversation */
    $byConversation = collect($response->json('rows'))->keyBy('conversation_id')->all();

    expect($byConversation[$conversationA->identifier()]['unread'])->toBe(2, 'no-marker unread is not the full count');
    expect($byConversation[$conversationA->identifier()]['has_draft'])->toBeTrue('the viewer\'s live draft is missing from the overlay');

    // Mark the FIRST read: exactly the second message stays unread.
    asTenant($tenant, fn () => app(ReadMarkers::class)->markRead($actor, $conversationA->identifier(), $first));
    $after = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/list?view=unassigned');
    /** @var array<string, array<string, mixed>> $afterRows */
    $afterRows = collect($after->json('rows'))->keyBy('conversation_id')->all();
    expect($afterRows[$conversationA->identifier()]['unread'])->toBe(1, 'the marker boundary did not move the unread count');

    // A COLLEAGUE still sees 2 — the overlay is per-viewer by construction.
    $their = $this->actingAs($colleague, 'staff')->getJson('https://localhost/inbox/list?view=unassigned');
    /** @var array<string, array<string, mixed>> $theirRows */
    $theirRows = collect($their->json('rows'))->keyBy('conversation_id')->all();
    expect($theirRows[$conversationA->identifier()]['unread'])->toBe(2, 'one viewer\'s marker leaked into another\'s overlay');
});

it('FR-10 sort override: ?sort=priority serves urgent-first with a working keyset cursor (the PREFERENCE persists per view in prefs.sort_overrides — FR-08\'s blob, client-written)', function (): void {
    [$tenant, $tenantId, $rows] = listScene(5);
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S09 Sort');

    $priorities = ['low', 'urgent', 'normal', 'high', 'urgent'];
    foreach ($rows as $i => [$ticket]) {
        DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->update(['priority' => $priorities[$i]]);
    }

    $page1 = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/list?view=unassigned&sort=priority&limit=3');
    $page1->assertOk();
    /** @var list<array<string, mixed>> $first */
    $first = $page1->json('rows');
    expect(array_map(fn (array $row): string => (string) $row['priority'], $first))
        ->toBe(['urgent', 'urgent', 'high'], 'priority override did not order urgent-first');

    $cursor = (string) $page1->json('next_cursor');
    $page2 = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/list?view=unassigned&sort=priority&limit=3&cursor='.urlencode($cursor));
    /** @var list<array<string, mixed>> $second */
    $second = $page2->json('rows');
    expect(array_map(fn (array $row): string => (string) $row['priority'], $second))
        ->toBe(['normal', 'low'], 'the keyset cursor lost or duplicated rows across the priority pages');
});

it('Doc 05 EC-04, the closing row\'s LIST half: one customer on TWO conversations with open tickets → BOTH rows carry dual_channel, the stale one carries older_channel_activity; resolving one collapses the group flags', function (): void {
    [$tenant, $conversationA, $ticketA] = ticketFixture('open');
    $tenantId = $tenant->identifier();

    // Second conversation for the SAME customer (the Doc 05 FR-05 hybrid).
    $conversationB = anotherConversation($tenantId, 'ec04', (string) $ticketA->customer_id);
    [$ticketB] = inboxTicketRow($tenantId, $conversationB, 'open');

    $projector = app(ListProjector::class);
    $projector->recomputeTicket($ticketA->identifier());
    // B gets fresher activity: an inbound message, then its recompute.
    inboundListMessage($tenantId, $conversationB->identifier(), 'fresher');
    $projector->recomputeTicket($ticketB->identifier());

    /** @var object{dual_channel: bool, older_channel_activity: bool} $rowA */
    $rowA = DB::table('ticket_list_items')->where('ticket_id', $ticketA->identifier())->first(['dual_channel', 'older_channel_activity']);
    /** @var object{dual_channel: bool, older_channel_activity: bool} $rowB */
    $rowB = DB::table('ticket_list_items')->where('ticket_id', $ticketB->identifier())->first(['dual_channel', 'older_channel_activity']);

    expect((bool) $rowA->dual_channel)->toBeTrue('the older row lost the dual-channel badge')
        ->and((bool) $rowB->dual_channel)->toBeTrue('the fresher row lost the dual-channel badge')
        ->and((bool) $rowA->older_channel_activity)->toBeTrue('the stale conversation is not flagged older-activity')
        ->and((bool) $rowB->older_channel_activity)->toBeFalse('the freshest conversation is wrongly flagged older-activity');

    // One ticket leaves the open family → a single-conversation group: no
    // badge anywhere (EC-04's "if both have open tickets").
    DB::table('tickets')->where('id', $ticketB->identifier())->update(['status' => 'resolved']);
    $projector->recomputeTicket($ticketB->identifier());

    /** @var object{dual_channel: bool, older_channel_activity: bool} $afterA */
    $afterA = DB::table('ticket_list_items')->where('ticket_id', $ticketA->identifier())->first(['dual_channel', 'older_channel_activity']);
    expect((bool) $afterA->dual_channel)->toBeFalse('the surviving row kept a badge for a one-conversation group')
        ->and((bool) $afterA->older_channel_activity)->toBeFalse();
});
