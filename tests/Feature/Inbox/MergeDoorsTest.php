<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListProjector;
use Illuminate\Support\Facades\DB;

/** The doors-file scene idiom, local to this file (Pest functions do not cross files). */
function mergeScene(): array
{
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S14 Merge Scene');
    app(ListProjector::class)->recomputeTicket($ticket->identifier());

    return [$tenant, $conversation, $ticket, $actor];
}

/*
|--------------------------------------------------------------------------
| S07 step 14 — B1-S04's merge doors: the SERVER-COMPUTED preview (the
| "What happens" panel renders machinery-derived facts) and the execution
| door over Doc 06's MergeTickets. SEC-06's same-customer truth is READ in
| the preview and ENFORCED by the service; the C-10 404-pattern covers any
| out-of-scope id in the set.
|--------------------------------------------------------------------------
*/

it('MERGE PREVIEW is server-computed machinery truth: newest-activity target, source cards with counts and tags, the unions, the confirm-phrase gate at >2 sources — and the EXECUTION door merges through the owning service (banner pointer, sources closed, survivor returned)', function (): void {
    [$tenant, $conversation, $ticket, $actor] = mergeScene();
    $tenantId = $tenant->identifier();
    grantPermissions($actor, [
        'tickets.view', 'conversations.view', 'views.manage', 'tickets.merge',
    ], 'S14 Merger Role');

    // Two more tickets for the SAME customer (SEC-06's precondition), each
    // in its own conversation, older activity than the target.
    $projector = app(ListProjector::class);
    $convB = anotherConversation($tenantId, 'merge-b', (string) $ticket->customer_id);
    [$sourceB] = inboxTicketRow($tenantId, $convB, 'open', ['tags' => '{withdrawal}']);
    $convC = anotherConversation($tenantId, 'merge-c', (string) $ticket->customer_id);
    [$sourceC] = inboxTicketRow($tenantId, $convC, 'open', ['tags' => '{vip}']);
    foreach ([$sourceB, $sourceC] as $source) {
        $projector->recomputeTicket($source->identifier());
    }
    // The scene ticket is the most recently active → the computed target.
    DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())
        ->update(['last_activity_at' => DB::raw('clock_timestamp()')]);

    $ids = [$ticket->identifier(), $sourceB->identifier(), $sourceC->identifier()];
    $preview = $this->actingAs($actor, 'staff')->getJson(
        'https://localhost/inbox/merge/preview?'.http_build_query(['ids' => $ids]),
    );
    $preview->assertOk();
    expect($preview->json('same_customer'))->toBeTrue()
        ->and($preview->json('target.ticket_id'))->toBe($ticket->identifier())
        ->and(count((array) $preview->json('sources')))->toBe(2)
        ->and($preview->json('confirm_phrase_required'))->toBeTrue()
        ->and($preview->json('effects.reversible_hours'))->toBe(24);
    /** @var list<string> $joining */
    $joining = (array) $preview->json('effects.tags_joining');
    sort($joining);
    expect($joining)->toBe(['vip', 'withdrawal']);

    // EXECUTION through the door → the owning service does everything.
    $merged = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/merge', [
        'target_id' => $ticket->identifier(),
        'source_ids' => [$sourceB->identifier(), $sourceC->identifier()],
    ]);
    $merged->assertOk();
    expect($merged->json('survivor_id'))->toBe($ticket->identifier());
    asTenant($tenant, function () use ($ticket, $sourceB, $sourceC): void {
        expect(DB::table('tickets')->where('id', $sourceB->identifier())->value('merged_into'))
            ->toBe($ticket->identifier())
            ->and(DB::table('tickets')->where('id', $sourceC->identifier())->value('merged_into'))
            ->toBe($ticket->identifier());
        // The unions landed on the survivor.
        $tags = (string) DB::table('tickets')->where('id', $ticket->identifier())->value('tags');
        expect($tags)->toContain('withdrawal')->toContain('vip');
    });
});

it('MERGE refusals are the machinery\'s own, C-04-shaped: a cross-customer set is REFUSED by the service (SEC-06), and an out-of-scope id in the set answers the C-10 404-pattern for the LOT', function (): void {
    [$tenant, , $ticket, $actor] = mergeScene();
    $tenantId = $tenant->identifier();
    grantPermissions($actor, [
        'tickets.view', 'conversations.view', 'views.manage', 'tickets.merge',
    ], 'S14 Merger Refusals Role');

    // A DIFFERENT customer's ticket (anotherConversation mints a new
    // customer when none is given).
    $convOther = anotherConversation($tenantId, 'merge-other');
    [$otherCustomers] = inboxTicketRow($tenantId, $convOther, 'open');
    app(ListProjector::class)->recomputeTicket($otherCustomers->identifier());

    $refused = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/merge', [
        'target_id' => $ticket->identifier(),
        'source_ids' => [$otherCustomers->identifier()],
    ]);
    $refused->assertStatus(422);
    expect($refused->json('code'))->toBe('transition_rejected');
    asTenant($tenant, function () use ($otherCustomers): void {
        expect(DB::table('tickets')->where('id', $otherCustomers->identifier())->value('merged_into'))->toBeNull();
    });

    // C-10: one unknown id in the set → 404-pattern, no partial oracle.
    $this->actingAs($actor, 'staff')->getJson(
        'https://localhost/inbox/merge/preview?'.http_build_query([
            'ids' => [$ticket->identifier(), '01999999-dead-7bbb-8ccc-000000000404'],
        ]),
    )->assertNotFound();
});
