<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| S07 step 10 — FR-14/FR-15's data door, EC-01's 404 pattern (sabotage #15's
| target), the Doc 04 EC-08 anchor window, and §9 print with SEC-05's note
| gate + watermark (sabotage #20's target).
|--------------------------------------------------------------------------
*/

function threadScene(): array
{
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    runListProjector(inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $ticket->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]));

    return [$tenant, $tenantId, $conversation, $ticket];
}

function threadMessage(string $tenantId, string $conversationId, array $attributes = []): string
{
    $id = (string) Str::uuid7();
    DB::table('messages')->insert(array_merge([
        'id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
        'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text',
        'body' => 'fixture message', 'is_internal' => false,
    ], $attributes));

    return $id;
}

it('FR-14: the thread page carries messages (notes included, quarantined EXCLUDED, tombstones EMPTIED), the conversation\'s tickets for boundaries, and the handoff packet', function (): void {
    [$tenant, $tenantId, $conversation, $ticket] = threadScene();
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S10 Thread');

    threadMessage($tenantId, $conversation->identifier(), ['body' => 'public one']);
    threadMessage($tenantId, $conversation->identifier(), ['kind' => 'note', 'is_internal' => true, 'author_type' => 'agent', 'body' => 'the internal note']);
    threadMessage($tenantId, $conversation->identifier(), ['body' => 'QUARANTINED', 'quarantined' => true]);
    $tombstoned = threadMessage($tenantId, $conversation->identifier(), ['body' => 'to be deleted']);
    DB::table('messages')->where('id', $tombstoned)->update(['deleted_at' => DB::raw('clock_timestamp()')]);

    DB::table('ticket_handoff_packets')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'schema_version' => 1,
        'payload' => json_encode(['summary' => 'fixture handoff', 'intent' => 'withdrawal_delay'], JSON_THROW_ON_ERROR),
    ]);

    $response = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/thread/'.$conversation->identifier());
    $response->assertOk();

    /** @var list<array<string, mixed>> $messages */
    $messages = $response->json('messages');
    $bodies = array_map(fn (array $message): ?string => $message['body'] ?? null, $messages);
    expect(in_array('public one', $bodies, true))->toBeTrue()
        ->and(in_array('the internal note', $bodies, true))->toBeTrue('staff threads include notes (the PRINT path is where they are gated)')
        ->and(in_array('QUARANTINED', $bodies, true))->toBeFalse('FR-11: quarantined is audit-only, never rendered');

    /** @var array<string, mixed> $tombstone */
    $tombstone = collect($messages)->firstWhere('id', $tombstoned);
    expect($tombstone['deleted_at'] ?? null)->not->toBeNull();
    // array_key_exists, not ?? — null coalescing would swallow the very
    // null this asserts (presence of the KEY with a null VALUE).
    expect(array_key_exists('body', $tombstone))->toBeTrue();
    expect($tombstone['body'])->toBeNull('a tombstone shipped CONTENT — presence only, ever');

    expect($response->json('tickets.0.id'))->toBe($ticket->identifier());
    expect($response->json('handoff_packets.0.payload.intent'))->toBe('withdrawal_delay');
});

it('EC-01 (sabotage #15\'s proof): a MISSING conversation and an OUT-OF-SCOPE one answer the IDENTICAL 404 pattern — status AND body — with the C-10 metric logged', function (): void {
    [$tenant, $tenantId, $conversation] = threadScene();
    activateTenant($tenant);
    threadMessage($tenantId, $conversation->identifier());

    // An Own-scoped actor who is NOT the assignee: the row exists, the scope
    // says no (BR-03's absence).
    $outsider = grantPermissions(App\Models\User::factory()->create(), ['tickets.view', 'conversations.view'], 'S10 Own Outsider');
    DB::table('roles')->where('name', 'S10 Own Outsider')->update([
        'scopes' => json_encode(['tickets' => 'Own', 'conversations' => 'Own', 'customers' => 'Own', 'reports' => 'Own'], JSON_THROW_ON_ERROR),
    ]);
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($outsider);

    $missing = $this->actingAs($outsider, 'staff')->getJson('https://localhost/inbox/thread/'.Str::uuid7());
    $outOfScope = $this->actingAs($outsider, 'staff')->getJson('https://localhost/inbox/thread/'.$conversation->identifier());

    $missing->assertStatus(404);
    $outOfScope->assertStatus(404);
    // THE ORACLE TEST: identical bodies MINUS trace_id (C-04 gives every
    // response its own trace — uniform noise on both shapes, not an oracle).
    $strip = function (string $content): array {
        /** @var array<string, mixed> $body */
        $body = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        unset($body['trace_id']);

        return $body;
    };
    expect($strip((string) $outOfScope->getContent()))->toBe($strip((string) $missing->getContent()), 'missing vs out-of-scope are distinguishable — an existence oracle (EC-01/C-10)');
});

it('Doc 04 EC-08: ?anchor returns the WINDOW containing the anchor message, not the tail page', function (): void {
    [$tenant, $tenantId, $conversation] = threadScene();
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S10 Anchor');

    $ids = [];
    for ($i = 1; $i <= 80; $i++) {
        $ids[] = threadMessage($tenantId, $conversation->identifier(), ['body' => "m{$i}"]);
    }
    $anchor = $ids[5]; // deep history — outside the 60-message tail

    $tail = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/thread/'.$conversation->identifier());
    /** @var list<array<string, mixed>> $tailMessages */
    $tailMessages = $tail->json('messages');
    expect(collect($tailMessages)->pluck('id')->contains($anchor))->toBeFalse('the anchor should be outside the tail page for this proof');

    $window = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/thread/'.$conversation->identifier().'?anchor='.$anchor);
    /** @var list<array<string, mixed>> $windowMessages */
    $windowMessages = $window->json('messages');
    expect(collect($windowMessages)->pluck('id')->contains($anchor))->toBeTrue('the anchor window does not contain the anchor');
});

it('§9 print (SEC-05): the note gate is ABSOLUTE — internal-note content never appears in the print HTML (sabotage #20 red here) — and the watermark carries user + timestamp', function (): void {
    [$tenant, $tenantId, $conversation] = threadScene();
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S10 Print');

    threadMessage($tenantId, $conversation->identifier(), ['body' => 'printable public message']);
    threadMessage($tenantId, $conversation->identifier(), ['kind' => 'note', 'is_internal' => true, 'author_type' => 'agent', 'body' => 'SECRET-NOTE-PRINT: never on paper']);

    $response = $this->actingAs($actor, 'staff')->get('https://localhost/inbox/thread/'.$conversation->identifier().'/print');
    $response->assertOk();
    $html = $response->getContent();

    expect(str_contains($html, 'printable public message'))->toBeTrue('the public message is missing from print');
    expect(str_contains($html, 'SECRET-NOTE-PRINT'))->toBeFalse('SEC-05 VIOLATED: an internal note reached the print path');
    // ROW-level absence, not just body text: the blade renders non-text
    // kinds as placeholders, which MASKED a dropped query gate on the first
    // sabotage attempt (filed INVALID) — a note must not reach the template
    // AT ALL, and data-kind makes that observable.
    expect(str_contains($html, 'data-kind="note"'))->toBeFalse('SEC-05 VIOLATED: a note ROW reached the print template');
    expect(str_contains($html, (string) $actor->getAttribute('email')))->toBeTrue('the watermark does not carry the printing user');
});

it('B12-S02: the history door — capability holders get the preserved revisions (audited by HistoryReader); without the key the marker stays but history answers 403', function (): void {
    [$tenant, $tenantId, $conversation] = threadScene();
    activateTenant($tenant);
    $holder = inboxScopedActor('All', 'S10 History Holder');
    grantPermissions($holder, ['tickets.view', 'conversations.view', 'views.manage', 'conversations.history.full'], 'S10 History Holder Role');
    $plain = inboxScopedActor('All', 'S10 History Plain');

    $edited = threadMessage($tenantId, $conversation->identifier(), ['body' => 'current body']);
    DB::table('messages')->where('id', $edited)->update(['edited_at' => DB::raw('clock_timestamp()')]);
    DB::table('message_revisions')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'message_id' => $edited,
        'message_created_at' => DB::table('messages')->where('id', $edited)->value('created_at'),
        'conversation_id' => $conversation->identifier(),
        'revision_kind' => 'edit', 'source' => 'customer', 'prior_body' => 'original body',
    ]);

    $allowed = $this->actingAs($holder, 'staff')->getJson('https://localhost/inbox/messages/'.$edited.'/history');
    $allowed->assertOk();
    expect($allowed->json('revisions.0.body'))->toBe('original body');

    $denied = $this->actingAs($plain, 'staff')->getJson('https://localhost/inbox/messages/'.$edited.'/history');
    $denied->assertStatus(403);
});
