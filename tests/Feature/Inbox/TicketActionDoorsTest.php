<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| S07 step 13 — the thin HTTP doors over Doc 06's headless property
| services (claim/unassign/assign/priority/tags + the two palette reads).
| Every verdict and audit entry below is the OWNING service's own; these
| tests prove the doors map scope misses to the 404-pattern, refusals to
| stable C-04 codes, and that the route-level key gates hold (positive
| controls: the same call succeeds WITH the key).
|
| All actors are minted BEFORE the first HTTP call (the OutboxChaos idiom:
| request teardown unbinds the harness tenant).
|--------------------------------------------------------------------------
*/

/** @return array{0: mixed, 1: mixed, 2: mixed, 3: User} */
function actionScene(string $status = 'open'): array
{
    [$tenant, $conversation, $ticket] = ticketFixture($status);
    activateTenant($tenant);
    $actor = inboxScopedActor('All', 'S13 Actions');
    grantPermissions($actor, [
        'tickets.view', 'conversations.view', 'views.manage',
        'tickets.assign', 'tickets.assign_self', 'tickets.priority.change', 'tickets.tags.manage',
    ], 'S13 Actions Role');
    app(ListProjector::class)->recomputeTicket($ticket->identifier());

    return [$tenant, $conversation, $ticket, $actor];
}

it('claim door: assigns the actor, recomputes the row, and the RACE loser gets 409 already_claimed {by} — the C-04 code is enum-stable', function (): void {
    [$tenant, , $ticket, $actor] = actionScene();
    $loser = inboxScopedActor('All', 'S13 Loser');
    grantPermissions($loser, ['tickets.view', 'conversations.view', 'views.manage', 'tickets.assign_self'], 'S13 Loser Role');

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/claim')
        ->assertOk();

    asTenant($tenant, function () use ($ticket, $actor): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))
            ->toBe($actor->identifier());
        expect(DB::table('ticket_list_items')->where('ticket_id', $ticket->identifier())->value('assignee_id'))
            ->toBe($actor->identifier(), 'the projector row did not converge');
    });

    $this->actingAs($loser, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/claim')
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_claimed')
        ->assertJsonPath('by', $actor->identifier());
});

it('unassign door (the mobile undo): returns the ticket to the queue through the owning service; without tickets.assign the route refuses', function (): void {
    [$tenant, , $ticket, $actor] = actionScene();
    $selfOnly = inboxScopedActor('All', 'S13 SelfOnly');
    grantPermissions($selfOnly, ['tickets.view', 'conversations.view', 'views.manage', 'tickets.assign_self'], 'S13 SelfOnly Role');

    $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/claim')->assertOk();
    $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/unassign')->assertOk();

    asTenant($tenant, function () use ($ticket): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBeNull();
    });

    $this->actingAs($selfOnly, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/unassign')
        ->assertForbidden();
});

it('assign door: assigns a named target; a disabled or unknown target answers 422 validation-shaped, never a 500', function (): void {
    [$tenant, , $ticket, $actor] = actionScene();
    $target = inboxScopedActor('All', 'S13 Target');
    grantPermissions($target, ['tickets.view', 'conversations.view', 'views.manage'], 'S13 Target Role');
    $disabled = inboxScopedActor('All', 'S13 Disabled');
    $disabled->forceFill(['disabled_at' => now()])->save();

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/assign', ['assignee_id' => $target->identifier()])
        ->assertOk();
    asTenant($tenant, function () use ($ticket, $target): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('assignee_id'))->toBe($target->identifier());
    });

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/assign', ['assignee_id' => $disabled->identifier()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('priority door: flips through ChangePriority (audit is the service\'s own row); an unknown level is refused at validation', function (): void {
    [$tenant, , $ticket, $actor] = actionScene();

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/priority', ['priority' => 'urgent'])
        ->assertOk();

    asTenant($tenant, function () use ($ticket): void {
        expect(DB::table('tickets')->where('id', $ticket->identifier())->value('priority'))->toBe('urgent');
        expect(DB::table('audit_log')->where('action_key', 'ticket.priority_changed')->count())
            ->toBeGreaterThanOrEqual(1, 'the owning service audits — a door that skips it went around the service');
    });

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/priority', ['priority' => 'blocker'])
        ->assertStatus(422);
});

it('tags door: add + remove in one request through TicketTags; suggest serves the prefix vocabulary; both gated on tickets.tags.manage', function (): void {
    [$tenant, , $ticket, $actor] = actionScene();
    $reader = inboxScopedActor('All', 'S13 Reader');

    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/tags/add', ['tags' => ['payments', 'withdrawal']])
        ->assertOk();
    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/tags/add', ['tags' => ['bonus']])
        ->assertOk();
    $this->actingAs($actor, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/tags/remove', ['tags' => ['payments']])
        ->assertOk()
        ->assertJsonPath('tags', ['bonus', 'withdrawal']);

    $suggest = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/tags/suggest?q=with');
    $suggest->assertOk();
    expect($suggest->json('tags'))->toContain('withdrawal');

    $this->actingAs($reader, 'staff')
        ->postJson('https://localhost/inbox/t/'.$ticket->identifier().'/tags/add', ['tags' => ['x']])
        ->assertForbidden();
    $this->actingAs($reader, 'staff')->getJson('https://localhost/inbox/tags/suggest?q=with')->assertForbidden();
});

it('assignees directory: active human staff only — disabled and service accounts never appear; gated on tickets.assign', function (): void {
    [, , , $actor] = actionScene();
    $visible = inboxScopedActor('All', 'S13 Directory');
    $visible->forceFill(['name' => 'Directory Fixture'])->save();
    $disabled = inboxScopedActor('All', 'S13 DirectoryGone');
    $disabled->forceFill(['name' => 'Directory Gone', 'disabled_at' => now()])->save();
    $reader = inboxScopedActor('All', 'S13 DirReader');

    $response = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/assignees?q=Directory');
    $response->assertOk();
    /** @var list<array{id: string, name: string}> $rows */
    $rows = $response->json('assignees');
    $names = array_column($rows, 'name');
    expect($names)->toContain('Directory Fixture')
        ->and($names)->not->toContain('Directory Gone');

    $this->actingAs($reader, 'staff')->getJson('https://localhost/inbox/assignees')->assertForbidden();
});

it('C-10: an out-of-scope ticket answers the 404-PATTERN on every action door — never 403, never an existence oracle', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    activateTenant($tenant);
    app(ListProjector::class)->recomputeTicket($ticket->identifier());
    // Own-scoped actor with every action KEY but no scope path to this
    // unassigned ticket — grantPermissions would silently widen the scope to
    // All (it did, on this test's first run), so the role is minted bespoke.
    $role = Role::query()->create([
        'name' => 'S13 Outsider Role',
        'color' => 'role-agent',
        'permissions' => [
            'tickets.view', 'conversations.view', 'views.manage',
            'tickets.assign', 'tickets.assign_self', 'tickets.priority.change', 'tickets.tags.manage',
        ],
        'scopes' => ['tickets' => 'Own', 'conversations' => 'Own', 'customers' => 'Own', 'reports' => 'Own'],
    ]);
    $outsider = User::factory()->create();
    $outsider->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($outsider);

    foreach ([
        ['/claim', []],
        ['/unassign', []],
        ['/assign', ['assignee_id' => $outsider->identifier()]],
        ['/priority', ['priority' => 'high']],
        ['/tags/add', ['tags' => ['x']]],
        ['/tags/remove', ['tags' => ['x']]],
    ] as [$path, $body]) {
        $this->actingAs($outsider, 'staff')
            ->postJson('https://localhost/inbox/t/'.$ticket->identifier().$path, $body)
            ->assertNotFound()
            ->assertJsonPath('code', 'resource_not_found');
    }
});

it('B12 timeline door (step 14): serves the relayed events newest-first with the frame\'s actor law — humans by name, automated events by class and NEVER a person\'s name; the expander count is honest; out-of-scope gets the 404-pattern', function (): void {
    [$tenant, $conversation, $ticket, $actor] = actionScene();
    $tenantId = $tenant->identifier();
    // Front-loaded (the file's standing pattern): ambient tenant context
    // does not survive the HTTP request cycles below. Bespoke Own-scoped
    // role, NOT grantPermissions — that helper silently widens scope to All
    // (the existing C-10 test's own recorded lesson).
    $outsiderRole = Role::query()->create([
        'name' => 'S14 Timeline Outsider Role',
        'color' => 'role-agent',
        'permissions' => ['tickets.view', 'conversations.view', 'views.manage'],
        'scopes' => ['tickets' => 'Own', 'conversations' => 'Own', 'customers' => 'Own', 'reports' => 'Own'],
    ]);
    $outsider = User::factory()->create();
    $outsider->forceFill(['role_id' => $outsiderRole->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($outsider);

    // Eight events into the relayed log (the door's truth source): a human
    // status change, a system reopen, and six filler creations to push past
    // the first page.
    inboxSeedEvent('ticket.status_changed', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'old_status' => 'open',
        'new_status' => 'pending_customer', 'actor_type' => 'agent', 'actor_id' => $actor->identifier(),
    ]);
    inboxSeedEvent('ticket.status_changed', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'old_status' => 'pending_customer',
        'new_status' => 'open', 'actor_type' => 'system', 'actor_id' => null,
    ]);
    foreach (range(1, 6) as $i) {
        inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
            'conversation_id' => $conversation->identifier(), 'customer_id' => $ticket->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]);
    }

    $response = $this->actingAs($actor, 'staff')->getJson(
        'https://localhost/inbox/t/'.$ticket->identifier().'/timeline',
    );
    $response->assertOk();
    expect($response->json('total'))->toBe(8)
        ->and(count((array) $response->json('rows')))->toBe(6, 'the first page is six rows; the expander serves the rest');

    // Newest first: the system reopen precedes the human pending in reverse
    // order (both after the fillers were seeded LAST — order is occurred_at
    // DESC, so the fillers lead). The actor law on the two status rows:
    $all = $this->actingAs($actor, 'staff')->getJson(
        'https://localhost/inbox/t/'.$ticket->identifier().'/timeline?all=1',
    );
    $all->assertOk();
    /** @var list<array{name: string, actor_type: string, actor_name: string|null, detail: array<string, mixed>}> $rows */
    $rows = (array) $all->json('rows');
    expect(count($rows))->toBe(8);
    $statusRows = array_values(array_filter($rows, fn (array $row): bool => $row['name'] === 'ticket.status_changed'));
    expect(count($statusRows))->toBe(2);
    // occurred_at DESC → the system reopen (seeded second) comes first.
    expect($statusRows[0]['actor_type'])->toBe('system')
        ->and($statusRows[0]['actor_name'])->toBeNull('an automated event carried a person\'s name — the frame\'s law broken')
        ->and($statusRows[0]['detail']['old_status'])->toBe('pending_customer')
        ->and($statusRows[1]['actor_type'])->toBe('agent')
        ->and($statusRows[1]['actor_name'])->toBe($actor->name)
        ->and($statusRows[1]['detail']['new_status'])->toBe('pending_customer');

    // C-10: a viewer whose scope excludes the ticket gets the 404-pattern.
    $this->actingAs($outsider, 'staff')->getJson(
        'https://localhost/inbox/t/'.$ticket->identifier().'/timeline',
    )->assertNotFound();
});
