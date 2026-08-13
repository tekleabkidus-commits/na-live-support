<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListProjector;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| R-22's class-2 fill (S07 step 14) — the tag manager DOORS over T-07
|--------------------------------------------------------------------------
|
| The engine's own physics (chunking, resumability, the merge DISTINCT, the
| audit count) are proven in FollowersPriorityTagsTest. These clauses prove
| the SURFACE's doors: the page serves the real inventory, the rewrite door
| drives the engine end-to-end AND converges the Doc 07 projection, the
| same-tag noop is unreachable BY VALIDATION (the D8/A-37 lesson applied at
| design time — the route declares ticket.tags_rewritten, and the engine's
| audit-free early return can never meet that declaration), and a killed run
| resumes through the same door because progress is the data.
*/

/**
 * One tenant, one operator, N tagged tickets WITH materialized list items.
 *
 * @return array{0: App\Models\Tenant, 1: App\Models\User, 2: list<string>}
 */
function tagManagerScene(int $tagged = 3, string $tag = 'telebirr-fix'): array
{
    [$tenant, $conversation, $ticket] = ticketFixture('open', ['tags' => sprintf('{%s}', $tag)]);
    activateTenant($tenant); // the admin chain's tenant.state gate
    $tenantId = $tenant->identifier();
    // Actors front-loaded before any HTTP cycle (the standing pattern).
    $operator = ticketActor(['tickets.tags.manage', 'tickets.view'], 'R22 Operator');

    $ids = [$ticket->identifier()];
    foreach (range(2, $tagged) as $i) {
        $c = anotherConversation($tenantId, "r22-{$i}-");
        [$extra] = inboxTicketRow($tenantId, $c, 'open', ['tags' => sprintf('{%s}', $tag)]);
        $ids[] = $extra->identifier();
    }

    $projector = app(ListProjector::class);
    foreach ($ids as $id) {
        $projector->recomputeTicket($id);
    }

    return [$tenant, $operator, $ids];
}

test('R-22 page door: /admin/tags serves the REAL inventory (name + count) behind tickets.tags.manage; an outsider without the key is refused', function (): void {
    [$tenant, $operator] = tagManagerScene(tagged: 3);
    $outsider = ticketActor(['tickets.view'], 'R22 Outsider');

    $page = $this->actingAs($operator, 'staff')->get('/admin/tags');
    $page->assertOk();
    $page->assertInertia(
        fn ($assert) => $assert
            ->component('Admin/Tags/TagManager', shouldExist: false)
            ->where('tags.0.name', 'telebirr-fix')
            ->where('tags.0.count', 3),
    );

    $this->actingAs($outsider, 'staff')->get('/admin/tags')->assertForbidden();
});

test('R-22 rewrite door: the rename runs THROUGH the engine (historical rows, audit count) and CONVERGES the Doc 07 projection set-based; the response carries the fresh inventory', function (): void {
    [$tenant, $operator, $ids] = tagManagerScene(tagged: 3);

    $response = $this->actingAs($operator, 'staff')->postJson(
        'https://localhost/admin/tags/rewrite',
        ['from' => 'telebirr-fix', 'to' => 'payments'],
    );
    $response->assertOk();
    expect($response->json('tickets_rewritten'))->toBe(3);
    expect($response->json('tags.0.name'))->toBe('payments');
    expect($response->json('tags.0.count'))->toBe(3);

    asTenant($tenant, function () use ($ids): void {
        foreach ($ids as $id) {
            // Ticket truth rewrote…
            $stored = (string) DB::table('tickets')->where('id', $id)->value('tags');
            expect($stored)->toContain('payments')->not->toContain('telebirr-fix');
            // …and the PROJECTION converged from it (syncTagsFromTruth).
            $projected = (string) DB::table('ticket_list_items')->where('ticket_id', $id)->value('tags');
            expect($projected)->toContain('payments')->not->toContain('telebirr-fix');
        }
        // The engine's own audit record, count intact, through the door.
        $audit = DB::table('audit_log')->where('action_key', 'ticket.tags_rewritten')->orderByDesc('created_at')->first();
        $payload = json_decode((string) $audit->payload, true, 512, JSON_THROW_ON_ERROR);
        expect($payload['tickets_rewritten'])->toBe(3)
            ->and($payload['from_tag'])->toBe('telebirr-fix')
            ->and($payload['to_tag'])->toBe('payments');
    });
});

test('R-22 refusals: blank and same-tag answer 422 validation-shaped (C-04) — the engine\'s audit-free noop is UNREACHABLE through the declaring route — and the engine\'s own refusal surfaces as a 422 problem', function (): void {
    [, $operator] = tagManagerScene(tagged: 1);

    $blank = $this->actingAs($operator, 'staff')->postJson(
        'https://localhost/admin/tags/rewrite',
        ['from' => '', 'to' => 'x'],
    );
    $blank->assertStatus(422);
    expect($blank->json('code'))->toBe('validation_failed');

    // from == to would return 0 BEFORE the engine's audit write while the
    // route declares ticket.tags_rewritten — exactly the D8 500. Validation
    // makes the path unreachable; this clause is the guard's regression.
    $same = $this->actingAs($operator, 'staff')->postJson(
        'https://localhost/admin/tags/rewrite',
        ['from' => 'telebirr-fix', 'to' => 'telebirr-fix'],
    );
    $same->assertStatus(422);
    expect($same->json('code'))->toBe('validation_failed');
});

test('R-22 mid-rewrite resume THROUGH the door: a killed run\'s half-done state (progress IS the data) is finished by simply asking again — the count names only the remaining rows', function (): void {
    [$tenant, $operator, $ids] = tagManagerScene(tagged: 4, tag: 'depo-delay');

    // A run killed halfway leaves exactly this: some rows already carry the
    // target, the rest still carry the source. Stage it directly.
    asTenant($tenant, function () use ($ids): void {
        DB::table('tickets')->whereIn('id', array_slice($ids, 0, 2))->update(['tags' => '{deposits}']);
    });

    $response = $this->actingAs($operator, 'staff')->postJson(
        'https://localhost/admin/tags/rewrite',
        ['from' => 'depo-delay', 'to' => 'deposits'],
    );
    $response->assertOk();
    expect($response->json('tickets_rewritten'))->toBe(2, 'the resume recounted rows a previous run already rewrote');

    asTenant($tenant, function () use ($ids): void {
        foreach ($ids as $id) {
            $stored = (string) DB::table('tickets')->where('id', $id)->value('tags');
            expect($stored)->toContain('deposits')->not->toContain('depo-delay');
            expect(substr_count($stored, 'deposits'))->toBe(1, 'the resume double-applied the target');
        }
    });
});
