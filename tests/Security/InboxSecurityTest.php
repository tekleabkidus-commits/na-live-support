<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Domain\Inbox\ReadModels\SystemViews;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 07 SEC posture — the scoped-list proof (S07 step 2; BR-03, T-01 layer 2)
|--------------------------------------------------------------------------
|
| THE PROOF THAT MUST EXIST BEFORE ANY LIST SERVES REAL DATA (the plan's
| ordered minimum): hydration is filtered through Doc 03's ScopeSets — the one
| constraint implementation (R-03) — so an id that reaches a ZSET wrongly
| still cannot hydrate out of scope, and a foreign tenant's id cannot hydrate
| at all (RLS + the Eloquent tenant scope, N-01's two layers).
|
| SABOTAGE #1's TARGET: remove the ScopeSets::apply call in
| ListReadModel::page and the Own-scope assertions here go red.
*/

/** A user whose role scopes tickets at the given level, holding tickets.view. */
function inboxScopedActor(string $level, string $roleName): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'color' => 'role-agent',
            'permissions' => ['tickets.view', 'conversations.view'],
            'scopes' => ['tickets' => $level, 'conversations' => $level, 'customers' => $level, 'reports' => $level],
        ],
    );
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

it('BR-03/scoped-list: an Own-scoped viewer hydrates ONLY their rows — absence, not locks; All-scope is the positive control (sabotage #1 goes red here)', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new'); // unassigned, open family
    $tenantId = $tenant->identifier();
    $own = inboxScopedActor('Own', 'S07 Own Scope');
    $all = inboxScopedActor('All', 'S07 All Scope');
    $other = User::factory()->create();

    // A second ticket assigned to OTHER, and one assigned to OWN.
    $c2 = anotherConversation($tenantId, 'sec-other');
    [$tOther] = inboxTicketRow($tenantId, $c2, 'open', ['assignee_id' => $other->identifier()]);
    $c3 = anotherConversation($tenantId, 'sec-own');
    [$tOwn] = inboxTicketRow($tenantId, $c3, 'open', ['assignee_id' => $own->identifier()]);

    foreach ([[$ticket, $conversation], [$tOther, $c2], [$tOwn, $c3]] as [$t, $c]) {
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    }

    $reader = app(ListReadModel::class);

    // Positive control FIRST: All-scope sees the unassigned row (the Own
    // emptiness below cannot be vacuous).
    $allPage = $reader->page('unassigned', 'unassigned', $all);
    expect(array_column($allPage, 'ticket_id'))->toContain($ticket->identifier());

    // Own scope: Doc 03's literal composition — an unassigned ticket is
    // assigned to nobody, so it is outside EVERY Own-scoped viewer's set
    // (A-11's own analysis; routing hands Own-scoped agents their work).
    $ownUnassigned = $reader->page('unassigned', 'unassigned', $own);
    expect($ownUnassigned)->toBe([], 'an Own-scoped viewer hydrated rows they hold no scope over');

    // A ZSET poisoned with ANOTHER user's ticket (a projector bug by
    // hypothesis) still cannot hydrate out of scope — layer 2 holds alone.
    $zsets = app(ViewZsets::class);
    $ownView = SystemViews::userView('my_open', $own->identifier());
    $zsets->add($ownView, $tOther->identifier(), 1_000_000);
    $page = $reader->page($ownView, 'my_open', $own);
    expect(array_column($page, 'ticket_id'))->toContain($tOwn->identifier())
        ->and(array_column($page, 'ticket_id'))->not->toContain($tOther->identifier(),
            'a poisoned membership hydrated OUT OF SCOPE — the hydration scope filter (sabotage #1\'s target) is gone');

    // BR-03: absence, never a placeholder — the page is exactly the in-scope rows.
    expect($page)->toHaveCount(1);
});

it('N-01: a FOREIGN TENANT\'s id planted in this tenant\'s ZSET hydrates NOTHING — the row exists, this tenant cannot see it', function (): void {
    // Tenant B with a real, projected ticket.
    [$tenantB, $convB, $ticketB] = ticketFixture('open');
    runListProjector(inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantB->identifier(), 'ticket_id' => $ticketB->identifier(),
        'conversation_id' => $convB->identifier(), 'customer_id' => $ticketB->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]));
    // Counted as tenant B on the APP connection — the harness transaction is
    // invisible to the platform connection, so a platform count would be
    // vacuously zero.
    $rowsThatExist = (int) DB::table('ticket_list_items')
        ->where('ticket_id', $ticketB->identifier())->count();

    // Tenant A, whose ZSET gets B's id planted.
    [$tenantA] = ticketFixture('open');
    $all = inboxScopedActor('All', 'S07 XT All Scope');
    $zsets = app(ViewZsets::class);
    $zsets->add('unassigned', $ticketB->identifier(), 2_000_000);

    $page = app(ListReadModel::class)->page('unassigned', 'unassigned', $all);
    $hydratedForeign = count(array_filter($page, fn (object $row): bool => $row->ticket_id === $ticketB->identifier()));

    expect($hydratedForeign)->toBeInvisibleGiven($rowsThatExist);
});

it('SEC posture: the hydration order is the ZSET\'s and out-of-scope ids leave NO gap artifacts (count residue would leak existence)', function (): void {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $own = inboxScopedActor('Own', 'S07 Own Scope 2');
    $other = User::factory()->create();

    $mine = [];
    foreach (range(1, 3) as $i) {
        $c = anotherConversation($tenantId, "order-{$i}");
        [$t] = inboxTicketRow($tenantId, $c, 'open', ['assignee_id' => $own->identifier()]);
        $mine[] = $t->identifier();
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    }

    // Poison two foreign memberships between mine.
    $zsets = app(ViewZsets::class);
    $view = SystemViews::userView('my_open', $own->identifier());
    $cX = anotherConversation($tenantId, 'poison');
    [$tX] = inboxTicketRow($tenantId, $cX, 'open', ['assignee_id' => $other->identifier()]);
    runListProjector(inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $tX->identifier(),
        'conversation_id' => $cX->identifier(), 'customer_id' => $tX->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]));
    $zsets->add($view, $tX->identifier(), 9_999_999_999_999_999);

    $page = app(ListReadModel::class)->page($view, 'my_open', $own);
    $ids = array_column($page, 'ticket_id');

    sort($ids);
    sort($mine);
    expect($ids)->toBe($mine, 'the page is not exactly the in-scope set');
});
