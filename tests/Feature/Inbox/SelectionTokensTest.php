<?php

declare(strict_types=1);

use App\Domain\Inbox\Selection\SelectionTokens;
use App\Domain\Tickets\Bulk\BulkOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| T-05 / SEC-06 / EC-02 / EC-05 (S07 step 7) — the token proofs the plan
| ordered BEFORE any bulk wiring. Sabotages #3 and #4 target this file.
|--------------------------------------------------------------------------
*/

function selectionScene(int $tickets, ?string $assignee = null): array
{
    [$tenant, $conversation, $ticket] = ticketFixture('open', $assignee !== null ? ['assignee_id' => $assignee] : []);
    $tenantId = $tenant->identifier();
    $ids = [$ticket->identifier()];

    $apply = function (App\Models\Ticket $t, App\Models\Conversation $c) use ($tenantId): void {
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    };
    $apply($ticket, $conversation);

    for ($i = 2; $i <= $tickets; $i++) {
        $c = anotherConversation($tenantId, "sel-{$i}");
        [$t] = inboxTicketRow($tenantId, $c, 'open', $assignee !== null ? ['assignee_id' => $assignee] : []);
        $ids[] = $t->identifier();
        $apply($t, $c);
    }

    return [$tenant, $tenantId, $ids];
}

it('T-05: mint carries the TRUE count; execution resolves the CURRENT membership with the drift report — "N items left the view and were skipped" is server truth (EC-02\'s tray renders it)', function (): void {
    [$tenant, $tenantId, $ids] = selectionScene(5);
    $actor = inboxScopedActor('All', 'S07 Sel Drift');
    grantPermissions($actor, ['tickets.view', 'conversations.view', 'views.manage', 'tickets.priority.change'], 'S07 Sel Drift Role');
    $tokens = app(SelectionTokens::class);

    $minted = $tokens->mint($actor, 'unassigned');
    expect($minted['count'])->toBe(5, 'the token does not carry the true count at mint');

    // Two tickets LEAVE the view between mint and execute (resolved via the
    // direct fixture write + the projector — the live membership moves).
    foreach (array_slice($ids, 0, 2) as $goneId) {
        DB::table('tickets')->where('id', $goneId)->update(['status' => 'resolved']);
        $conversationId = (string) DB::table('tickets')->where('id', $goneId)->value('conversation_id');
        runListProjector(inboxSeedEvent('ticket.status_changed', [
            'tenant_id' => $tenantId, 'ticket_id' => $goneId,
            'conversation_id' => $conversationId, 'old_status' => 'open',
            'new_status' => 'resolved', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    }

    $result = $tokens->execute($actor, $minted['id'], 'priority', ['priority' => 'high']);
    expect($result['resolved'])->toBe(3, 'execution did not resolve the CURRENT membership')
        ->and($result['skipped'])->toBe(2, 'the drift report lost the items that left the view')
        ->and($result['bulk_job_ids'])->toHaveCount(1);

    // The bulk job really targeted the three CURRENT members, none departed.
    /** @var object{total: int} $job */
    $job = DB::table('ticket_bulk_jobs')->where('id', $result['bulk_job_ids'][0])->first();
    expect((int) $job->total)->toBe(3);
});

it('SEC-06: the token is USER-BOUND (a colleague gets the same silent not-found) and SINGLE-USE (the second execute refuses — sabotage #4 goes red here)', function (): void {
    [$tenant, $tenantId] = selectionScene(3);
    $minter = inboxScopedActor('All', 'S07 Sel Owner');
    grantPermissions($minter, ['tickets.view', 'conversations.view', 'views.manage', 'tickets.priority.change'], 'S07 Sel Owner Role');
    $colleague = inboxScopedActor('All', 'S07 Sel Colleague');
    grantPermissions($colleague, ['tickets.view', 'conversations.view', 'views.manage', 'tickets.priority.change'], 'S07 Sel Colleague Role');
    $tokens = app(SelectionTokens::class);

    $minted = $tokens->mint($minter, 'unassigned');

    // USER-BOUND: the colleague's attempt answers exactly like a missing
    // token — no existence probing (C-10), nothing consumed.
    expect(fn () => $tokens->execute($colleague, $minted['id'], 'priority', ['priority' => 'high']))
        ->toThrow(DomainException::class, 'token_not_found');
    expect(DB::table('inbox_selection_tokens')->where('id', $minted['id'])->value('consumed_at'))->toBeNull('a foreign attempt consumed the token');

    // SINGLE-USE: first execute lands; the replay refuses on the latch.
    $tokens->execute($minter, $minted['id'], 'priority', ['priority' => 'high']);
    expect(fn () => $tokens->execute($minter, $minted['id'], 'priority', ['priority' => 'high']))
        ->toThrow(DomainException::class, 'token_consumed');
});

it('EC-05: a selection past the Doc 06 cap executes as QUEUED BATCHES — every submitted batch ≤ BulkOperation::MAX_ITEMS (sabotage #3: chunking removed → Doc 06\'s own bulk-limit refusal turns this red)', function (): void {
    [$tenant, $tenantId] = selectionScene(BulkOperation::MAX_ITEMS + 1); // 201
    $actor = inboxScopedActor('All', 'S07 Sel Chunks');
    grantPermissions($actor, ['tickets.view', 'conversations.view', 'views.manage', 'tickets.priority.change'], 'S07 Sel Chunks Role');
    $tokens = app(SelectionTokens::class);

    $minted = $tokens->mint($actor, 'unassigned');
    expect($minted['count'])->toBe(BulkOperation::MAX_ITEMS + 1);

    $result = $tokens->execute($actor, $minted['id'], 'priority', ['priority' => 'high']);
    expect($result['resolved'])->toBe(BulkOperation::MAX_ITEMS + 1)
        ->and($result['bulk_job_ids'])->toHaveCount(2, 'the 201-intent did not chunk into queued batches');

    // The Doc 06 cap holds on EVERY submitted batch — and the batches sum to
    // the whole selection (nothing silently dropped).
    $totals = DB::table('ticket_bulk_jobs')->whereIn('id', $result['bulk_job_ids'])->pluck('total')->all();
    foreach ($totals as $total) {
        expect((int) $total)->toBeLessThanOrEqual(BulkOperation::MAX_ITEMS, 'a submitted batch exceeds FR-25\'s cap');
    }
    expect(array_sum($totals))->toBe(BulkOperation::MAX_ITEMS + 1);
});

it('the HTTP doors: both ride views.manage (the R-26 principle, third application — a token row is a workspace write); execute audited with action + drift counts (SEC-03); a consumed token answers 409 with the stable code', function (): void {
    [$tenant, $tenantId] = selectionScene(3);
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S07 Sel HTTP');
    grantPermissions($actor, ['tickets.view', 'conversations.view', 'views.manage', 'tickets.priority.change'], 'S07 Sel HTTP Role');

    $minted = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/selections', ['view_key' => 'unassigned']);
    $minted->assertStatus(201);
    expect($minted->json('count'))->toBe(3);

    $executed = $this->actingAs($actor, 'staff')->postJson("https://localhost/inbox/selections/{$minted->json('id')}/execute", ['action' => 'priority', 'params' => ['priority' => 'high']]);
    $executed->assertOk();
    expect($executed->json('resolved'))->toBe(3)
        ->and($executed->json('skipped'))->toBe(0);

    asTenant($tenant, function (): void {
        expect(DB::table('audit_log')->where('action_key', 'inbox.selection_executed')->count())->toBeGreaterThanOrEqual(1);
    });

    $replay = $this->actingAs($actor, 'staff')->postJson("https://localhost/inbox/selections/{$minted->json('id')}/execute", ['action' => 'priority', 'params' => ['priority' => 'high']]);
    $replay->assertStatus(409);
});

it('R-26 third application, the EXECUTED refusal: a Read Only-shaped actor (view keys only, no views.manage) is refused at BOTH selection doors — the sweep\'s zero-writes bar holds by computed physics, not by this route\'s good manners', function (): void {
    [$tenant, $tenantId] = selectionScene(3);
    activateTenant($tenant);
    $reader = grantPermissions(User::factory()->create(), ['tickets.view', 'conversations.view'], 'S07 Sel Reader Role');

    $mint = $this->actingAs($reader, 'staff')->postJson('https://localhost/inbox/selections', ['view_key' => 'unassigned']);
    $mint->assertStatus(403);
    asTenant($tenant, function (): void {
        expect(DB::table('inbox_selection_tokens')->count())->toBe(0, 'the refused mint still wrote a token row');
    });

    $execute = $this->actingAs($reader, 'staff')->postJson('https://localhost/inbox/selections/'.Illuminate\Support\Str::uuid7().'/execute', ['action' => 'priority', 'params' => ['priority' => 'high']]);
    $execute->assertStatus(403);
});
