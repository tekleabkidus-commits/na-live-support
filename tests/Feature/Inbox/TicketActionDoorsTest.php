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
