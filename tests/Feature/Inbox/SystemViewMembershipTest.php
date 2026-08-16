<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Domain\Inbox\ReadModels\SystemViews;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| AC-02's first clause (S07 step 4): "system-view membership fixtures (per
| FR-05 definitions) match direct queries exactly" — each of the seven views
| gets EXPLICIT include AND exclude cases, then the materialized set is
| compared member-for-member with SystemViews::directQuery (the same
| definition NFR-07's checker runs). A-32's mention definition as ruled.
|--------------------------------------------------------------------------
*/

function membershipScene(): array
{
    [$tenant, $conversation, $ticket] = ticketFixture('new'); // unassigned NEW
    $tenantId = $tenant->identifier();
    $u1 = User::factory()->create();

    $apply = function (App\Models\Ticket $t, App\Models\Conversation $c) use ($tenantId): void {
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    };
    $apply($ticket, $conversation);

    return [$tenant, $tenantId, $u1, $apply, $ticket, $conversation];
}

function assertMatchesDirect(string $viewKey, string $view, ?string $userId = null): void
{
    $zsets = app(ViewZsets::class);
    $truth = SystemViews::directQuery($view, $userId)->pluck('id')->all();
    $materialized = $zsets->allIds($viewKey);
    sort($truth);
    sort($materialized);
    expect($materialized)->toBe($truth, "{$viewKey}: membership diverges from the FR-05 direct query");
}

it('My Open: assignee=me AND open-family — a resolved assignment and a colleague\'s ticket are EXCLUDED (FR-05/FR-10)', function (): void {
    [, $tenantId, $u1, $apply] = membershipScene();
    $u2 = User::factory()->create();

    $cMine = anotherConversation($tenantId, 'mine');
    [$tMine] = inboxTicketRow($tenantId, $cMine, 'open', ['assignee_id' => $u1->identifier()]);
    $apply($tMine, $cMine);

    $cTheirs = anotherConversation($tenantId, 'theirs');
    [$tTheirs] = inboxTicketRow($tenantId, $cTheirs, 'open', ['assignee_id' => $u2->identifier()]);
    $apply($tTheirs, $cTheirs);

    $cResolved = anotherConversation($tenantId, 'resolved-mine');
    [$tResolved] = inboxTicketRow($tenantId, $cResolved, 'resolved', ['assignee_id' => $u1->identifier()]);
    $apply($tResolved, $cResolved);

    $view = SystemViews::userView('my_open', $u1->identifier());
    $ids = app(ViewZsets::class)->allIds($view);
    expect($ids)->toContain($tMine->identifier())
        ->and($ids)->not->toContain($tTheirs->identifier())
        ->and($ids)->not->toContain($tResolved->identifier());
    assertMatchesDirect($view, 'my_open', $u1->identifier());
});

it('Unassigned: scope-visible base = assignee ∅ AND open-family — an assigned ticket and a resolved orphan are EXCLUDED; first-come order (BR-04)', function (): void {
    [, $tenantId, $u1, $apply, $ticket] = membershipScene();

    $cAssigned = anotherConversation($tenantId, 'assigned');
    [$tAssigned] = inboxTicketRow($tenantId, $cAssigned, 'open', ['assignee_id' => $u1->identifier()]);
    $apply($tAssigned, $cAssigned);

    $cResolved = anotherConversation($tenantId, 'resolved-orphan');
    [$tResolved] = inboxTicketRow($tenantId, $cResolved, 'resolved');
    $apply($tResolved, $cResolved);

    $cSecond = anotherConversation($tenantId, 'second-unassigned');
    [$tSecond] = inboxTicketRow($tenantId, $cSecond, 'new');
    // 0j's deterministic fix (ruled at the S08 step-10 acceptance): a
    // FIRST-COME contract tested with rows that can carry colliding db-time
    // keys tests the collision, not the contract. created_at is db-time-only
    // through the app grant (deliberate physics), so the FIXTURE separates
    // the rows at the MIGRATOR level — fixture plumbing outside app/, the
    // InboxBrowserSeeder status-write precedent. The collision itself is NOT
    // the behavior under test.
    DB::connection('pgsql_migrator')->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
    DB::connection('pgsql_migrator')->statement("UPDATE tickets SET created_at = created_at + interval '2 seconds' WHERE id = ?", [$tSecond->identifier()]);
    $apply($tSecond, $cSecond);

    $zsets = app(ViewZsets::class);
    $ids = $zsets->allIds('unassigned'); // ascending score = created asc
    expect($ids)->toBe([$ticket->identifier(), $tSecond->identifier()], 'first-come order broke (BR-04)')
        ->and($ids)->not->toContain($tAssigned->identifier())
        ->and($ids)->not->toContain($tResolved->identifier());
    assertMatchesDirect('unassigned', 'unassigned');
});

it('Mentions (A-32 as ruled): unresolved from note.mentioned until thread-open/dismiss — resolution EXCLUDES; a second unresolved mention keeps membership', function (): void {
    [, $tenantId, $u1, , $ticket, $conversation] = membershipScene();

    $mention = fn (): string => inboxSeedEvent('note.mentioned', [
        'tenant_id' => $tenantId, 'message_id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->identifier(),
        'author_id' => (string) Str::uuid7(), 'mentioned_user_id' => $u1->identifier(),
    ]);
    runListProjector($mention());

    $view = SystemViews::userView('mentions', $u1->identifier());
    expect(app(ViewZsets::class)->allIds($view))->toContain($ticket->identifier());
    assertMatchesDirect($view, 'mentions', $u1->identifier());

    // Resolution (the A-32 door) — membership leaves AND the direct query agrees.
    app(ListProjector::class)->markMentionsResolved($u1->identifier(), $ticket->identifier());
    expect(app(ViewZsets::class)->allIds($view))->not->toContain($ticket->identifier());
    assertMatchesDirect($view, 'mentions', $u1->identifier());

    // A fresh mention re-enters (unresolved rows are what count, not history).
    runListProjector($mention());
    expect(app(ViewZsets::class)->allIds($view))->toContain($ticket->identifier());
    assertMatchesDirect($view, 'mentions', $u1->identifier());
});

it('Following: ticket_followers truth, any status; unfollow EXCLUDES (FR-05)', function (): void {
    [, $tenantId, $u1, $apply] = membershipScene();

    $c = anotherConversation($tenantId, 'followed');
    [$t] = inboxTicketRow($tenantId, $c, 'resolved'); // ANY status — deliberately not open-family
    DB::table('ticket_followers')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
        'ticket_id' => $t->identifier(), 'user_id' => $u1->identifier(), 'added_by' => $u1->identifier(),
    ]);
    $apply($t, $c);
    runListProjector(inboxSeedEvent('ticket.follower_added', [
        'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(), 'user_id' => $u1->identifier(), 'by' => $u1->identifier(),
    ]));

    $view = SystemViews::userView('following', $u1->identifier());
    expect(app(ViewZsets::class)->allIds($view))->toContain($t->identifier());
    assertMatchesDirect($view, 'following', $u1->identifier());

    DB::table('ticket_followers')->where('ticket_id', $t->identifier())->delete();
    runListProjector(inboxSeedEvent('ticket.follower_removed', [
        'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(), 'user_id' => $u1->identifier(), 'by' => $u1->identifier(),
    ]));
    expect(app(ViewZsets::class)->allIds($view))->not->toContain($t->identifier());
    assertMatchesDirect($view, 'following', $u1->identifier());
});

it('SLA at risk: warned/breached AND open-family (FR-05) — a breached RESOLVED ticket is EXCLUDED; time-to-breach order (FR-10)', function (): void {
    [, $tenantId, , $apply] = membershipScene();
    $projector = app(ListProjector::class);

    $mk = function (string $label, string $status, string $slaState, string $offset) use ($tenantId, $apply, $projector): string {
        $c = anotherConversation($tenantId, $label);
        [$t] = inboxTicketRow($tenantId, $c, $status);
        $apply($t, $c);
        $due = DB::selectOne("SELECT (clock_timestamp() + interval '{$offset}') AS t")->t;
        DB::table('ticket_list_items')->where('ticket_id', $t->identifier())
            ->update(['sla_state' => $slaState, 'sla_due_at' => $due]);
        $projector->recomputeTicket($t->identifier());

        return $t->identifier();
    };

    $later = $mk('sla-later', 'open', 'warned', '2 hours');
    $sooner = $mk('sla-sooner', 'open', 'breached', '10 minutes');
    $resolved = $mk('sla-resolved', 'resolved', 'breached', '5 minutes');
    $healthy = $mk('sla-none', 'open', 'ok', '1 hour'); // not warned/breached

    $ids = app(ViewZsets::class)->allIds('sla_at_risk'); // asc = time-to-breach asc
    expect($ids)->toBe([$sooner, $later])
        ->and($ids)->not->toContain($resolved)
        ->and($ids)->not->toContain($healthy);
    assertMatchesDirect('sla_at_risk', 'sla_at_risk');
});

it('Expiring windows: closing/expired WITH open tickets (FR-05/Doc 05 FR-22) — an expired window on a RESOLVED ticket is EXCLUDED', function (): void {
    [, $tenantId, , $apply] = membershipScene();

    $mk = function (string $label, string $status, string $windowState) use ($tenantId, $apply): string {
        $c = anotherConversation($tenantId, $label);
        [$t] = inboxTicketRow($tenantId, $c, $status);
        $expiry = DB::selectOne("SELECT (clock_timestamp() + interval '30 minutes') AS t")->t;
        DB::table('conversations')->where('id', $c->identifier())
            ->update(['reply_window_state' => $windowState, 'reply_window_expires_at' => $expiry]);
        $apply($t, $c);
        runListProjector(inboxSeedEvent('telegram.window.closing', [
            'tenant_id' => $tenantId, 'conversation_id' => $c->identifier(), 'expires_at' => (string) $expiry,
        ]));

        return $t->identifier();
    };

    $closing = $mk('w-closing', 'open', 'closing');
    $expired = $mk('w-expired', 'pending_customer', 'expired');
    $resolvedExpired = $mk('w-resolved', 'resolved', 'expired');
    $openWindowOpen = $mk('w-open', 'open', 'open');

    $ids = app(ViewZsets::class)->allIds('expiring_windows');
    expect($ids)->toContain($closing)
        ->and($ids)->toContain($expired)
        ->and($ids)->not->toContain($resolvedExpired)
        ->and($ids)->not->toContain($openWindowOpen);
    assertMatchesDirect('expiring_windows', 'expiring_windows');
});

it('Rescue: channel_unreachable=true (FR-05/Doc 05 FR-10) — clearing the flag EXCLUDES', function (): void {
    [, $tenantId, , $apply] = membershipScene();

    $c = anotherConversation($tenantId, 'rescue-m');
    [$t] = inboxTicketRow($tenantId, $c, 'open');
    DB::table('conversations')->where('id', $c->identifier())->update(['business_connection_id' => 'conn-m-1']);
    DB::table('tickets')->where('id', $t->identifier())->update(['channel_unreachable' => true]);
    $apply($t, $c);
    runListProjector(inboxSeedEvent('telegram.connection.revoked', [
        'tenant_id' => $tenantId, 'bot_id' => (string) Str::uuid7(), 'connection_id' => 'conn-m-1', 'account_user_id' => 900003333,
    ]));

    expect(app(ViewZsets::class)->allIds('rescue'))->toContain($t->identifier());
    assertMatchesDirect('rescue', 'rescue');

    DB::table('tickets')->where('id', $t->identifier())->update(['channel_unreachable' => false]);
    runListProjector(inboxSeedEvent('telegram.connection.active', [
        'tenant_id' => $tenantId, 'bot_id' => (string) Str::uuid7(), 'connection_id' => 'conn-m-1', 'account_user_id' => 900003333,
    ]));
    expect(app(ViewZsets::class)->allIds('rescue'))->not->toContain($t->identifier());
    assertMatchesDirect('rescue', 'rescue');
});
