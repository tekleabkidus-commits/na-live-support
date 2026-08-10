<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Domain\Inbox\Views\SavedViewFilters;
use App\Domain\Inbox\Views\SavedViews;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| FR-06 / AC-02's second clause (S07 step 4): saved-view CRUD + share +
| duplicate-to-edit round-trips; definitions materialize (T-01) and are
| incrementally patched; the filter set's two executable forms agree.
|--------------------------------------------------------------------------
*/

it('FR-06: create materializes the definition; update RECOMPUTES it; the projector patches it incrementally; sql() and matches() agree row-for-row', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    $owner = inboxScopedActor('All', 'S07 SV Owner');
    $savedViews = app(SavedViews::class);
    $zsets = app(ViewZsets::class);

    $apply = function (App\Models\Ticket $t, App\Models\Conversation $c) use ($tenantId): void {
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    };
    $apply($ticket, $conversation);

    $cUrgent = anotherConversation($tenantId, 'sv-urgent');
    [$tUrgent] = inboxTicketRow($tenantId, $cUrgent, 'open', ['priority' => 'urgent']);
    $apply($tUrgent, $cUrgent);

    // Create AFTER rows exist: materialization is the full recompute.
    $viewId = $savedViews->create($owner, 'Urgent open', ['status' => ['open'], 'priority' => ['urgent']]);
    expect($zsets->allIds('saved:'.$viewId))->toBe([$tUrgent->identifier()]);

    // Incremental patch: a NEW urgent open ticket joins on its event alone.
    $cLater = anotherConversation($tenantId, 'sv-later');
    [$tLater] = inboxTicketRow($tenantId, $cLater, 'open', ['priority' => 'urgent']);
    $apply($tLater, $cLater);
    expect($zsets->allIds('saved:'.$viewId))->toContain($tLater->identifier());

    // …and a status leave patches OUT.
    DB::table('tickets')->where('id', $tLater->identifier())->update(['status' => 'resolved']);
    runListProjector(inboxSeedEvent('ticket.status_changed', [
        'tenant_id' => $tenantId, 'ticket_id' => $tLater->identifier(),
        'conversation_id' => $cLater->identifier(), 'old_status' => 'open',
        'new_status' => 'resolved', 'actor_type' => 'agent', 'actor_id' => null,
    ]));
    expect($zsets->allIds('saved:'.$viewId))->not->toContain($tLater->identifier());

    // Definition change → full recompute to the NEW filter set (T-01).
    $savedViews->update($owner, $viewId, 'Any new', ['status' => ['new']]);
    expect($zsets->allIds('saved:'.$viewId))->toBe([$ticket->identifier()]);

    // The two executable forms agree over every row (the NFR-07 discipline).
    foreach ([['status' => ['new']], ['priority' => ['urgent']], ['status' => ['open'], 'priority' => ['urgent']]] as $filters) {
        $sqlIds = SavedViewFilters::sql(
            DB::table('ticket_list_items as li')->selectRaw('li.ticket_id AS id, li.last_activity_at AS sort_at'),
            $filters,
        )->pluck('id')->all();

        $matchIds = [];
        foreach (DB::table('ticket_list_items')->get() as $row) {
            /** @var object{ticket_id: string} $row */
            if (SavedViewFilters::matches($filters, $row)) {
                $matchIds[] = $row->ticket_id;
            }
        }
        sort($sqlIds);
        sort($matchIds);
        expect($matchIds)->toBe($sqlIds, 'sql() and matches() disagree — the materializer and the projector would drift (the NFR-07 failure class)');
    }
});

it('AC-02 round-trip: share requires views.share (route-gated + audited), consumers get READ-ONLY + duplicate-to-edit, unshare reverts', function (): void {
    [$tenant] = ticketFixture('open');
    activateTenant($tenant); // the tenant.state:staff middleware admits ACTIVE tenants only
    $owner = inboxScopedActor('All', 'S07 SV Sharer');
    grantPermissions($owner, ['tickets.view', 'conversations.view', 'views.share'], 'S07 SV Sharer Role');
    $consumer = inboxScopedActor('All', 'S07 SV Consumer');
    $savedViews = app(SavedViews::class);

    $viewId = asTenant($tenant, fn (): string => $savedViews->create($owner, 'Team view', ['status' => ['open']]));

    // HTTP: the share route demands views.share — the consumer (holder of
    // tickets.view only) is refused by the PERMISSION layer…
    $denied = $this->actingAs($consumer, 'staff')->postJson("https://localhost/inbox/views/{$viewId}/share", ['to_type' => 'team', 'to_id' => (string) Str::uuid7()]);
    expect($denied->status())->toBeIn([403, 404], 'a non-holder reached the share door');

    // …and the OWNER (holder) shares — audited via the route middleware.
    $shared = $this->actingAs($owner, 'staff')->postJson("https://localhost/inbox/views/{$viewId}/share", ['to_type' => 'team', 'to_id' => (string) Str::uuid7()]);
    $shared->assertOk();

    // READ-ONLY to consumers: edit refused with the stable code…
    $edit = $this->actingAs($consumer, 'staff')->patchJson("https://localhost/inbox/views/{$viewId}", ['name' => 'hijack', 'filters' => ['status' => ['open']]]);
    $edit->assertStatus(403);
    expect($edit->json('code'))->toBe('read_only_view');

    // …duplicate-to-edit works and yields the consumer's OWN unshared copy.
    $dup = $this->actingAs($consumer, 'staff')->postJson("https://localhost/inbox/views/{$viewId}/duplicate");
    $dup->assertStatus(201);
    $copyId = $dup->json('id');
    /** @var object{owner_user_id: string, shared_to_type: string|null, filters: string} $copy */
    $copy = asTenant($tenant, fn (): ?object => DB::table('inbox_saved_views')->where('id', $copyId)->first());
    expect($copy->owner_user_id)->toBe($consumer->identifier())
        ->and($copy->shared_to_type)->toBeNull()
        ->and($copy->filters)->toBe((string) asTenant($tenant, fn () => DB::table('inbox_saved_views')->where('id', $viewId)->value('filters')));

    // The copy IS editable by its new owner (the round-trip's point).
    $this->actingAs($consumer, 'staff')->patchJson("https://localhost/inbox/views/{$copyId}", ['name' => 'mine now', 'filters' => ['status' => ['new']]])->assertOk();

    // Unshare reverts; the audit rows exist for both share acts.
    $this->actingAs($owner, 'staff')->deleteJson("https://localhost/inbox/views/{$viewId}/share")->assertOk();
    asTenant($tenant, function () use ($viewId): void {
        expect(DB::table('inbox_saved_views')->where('id', $viewId)->value('shared_to_type'))->toBeNull();
    });
    asTenant($tenant, function (): void {
        expect(DB::table('audit_log')->where('action_key', 'inbox.view_shared')->count())->toBeGreaterThanOrEqual(1)
            ->and(DB::table('audit_log')->where('action_key', 'inbox.view_unshared')->count())->toBeGreaterThanOrEqual(1);
    });
});

it('FR-06: the filter key set is CLOSED — an unknown key refuses with the stable C-04 code; deletion is the tombstone + de-materialization', function (): void {
    [$tenant] = ticketFixture('open');
    activateTenant($tenant);
    $owner = inboxScopedActor('All', 'S07 SV Closer');
    $savedViews = app(SavedViews::class);

    $refused = $this->actingAs($owner, 'staff')->postJson('https://localhost/inbox/views', ['name' => 'bad', 'filters' => ['sql_injection' => '1']]);
    $refused->assertStatus(422);
    expect($refused->json('code'))->toBe('view_filters_invalid');

    // activateTenant leaves the harness context unbound — re-bind for the
    // direct service calls (HTTP binds its own per request).
    $viewId = asTenant($tenant, fn (): string => $savedViews->create($owner, 'Doomed', ['status' => ['open']]));
    expect(asTenant($tenant, fn (): array => $savedViews->materializedIds()))->toContain($viewId);

    $this->actingAs($owner, 'staff')->deleteJson("https://localhost/inbox/views/{$viewId}")->assertOk();
    asTenant($tenant, function () use ($savedViews, $viewId): void {
        expect((string) DB::table('inbox_saved_views')->where('id', $viewId)->value('deleted_at'))->not->toBe('')
            ->and($savedViews->materializedIds())->not->toContain($viewId)
            ->and(app(ViewZsets::class)->count('saved:'.$viewId))->toBe(0);
    });
});

it('FR-08: view order persists per user through the prefs door, merged per key', function (): void {
    [$tenant] = ticketFixture('open');
    activateTenant($tenant);
    $user = inboxScopedActor('All', 'S07 Prefs User');

    $order = ['system:my_open', 'system:unassigned', 'saved:abc'];
    $this->actingAs($user, 'staff')->patchJson('https://localhost/inbox/prefs', ['prefs' => ['view_order' => $order]])->assertOk();
    $this->actingAs($user, 'staff')->patchJson('https://localhost/inbox/prefs', ['prefs' => ['density' => 'compact']])->assertOk();

    $show = $this->actingAs($user, 'staff')->getJson('https://localhost/inbox/prefs');
    $show->assertOk();
    expect($show->json('prefs.view_order'))->toBe($order, 'a later partial write clobbered view_order — the merge is not per key')
        ->and($show->json('prefs.density'))->toBe('compact');

    $refused = $this->actingAs($user, 'staff')->patchJson('https://localhost/inbox/prefs', ['prefs' => ['arbitrary' => 1]]);
    $refused->assertStatus(422);
});

it('R-26/A-34: Read Only cannot WRITE views (the SEV-1, flipped by the mapping alone) but still READS system views; the JUNIOR AGENT template creates one (the audience proof)', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    activateTenant($tenant);
    $tenantId = $tenant->identifier();

    // The REAL seeded templates — the ruling's subjects, not lookalikes.
    asTenant($tenant, fn (): int => app(App\Domain\Rbac\RoleSeeder::class)->seed());
    $roles = asTenant($tenant, fn () => App\Models\Role::query()->get()->keyBy('name'));

    $readOnly = App\Models\User::factory()->create();
    $readOnly->forceFill(['role_id' => $roles['Read Only']->getKey()])->save();
    $junior = App\Models\User::factory()->create();
    $junior->forceFill(['role_id' => $roles['Junior Agent']->getKey()])->save();
    foreach ([$readOnly, $junior] as $u) {
        app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($u);
    }

    // 1 — the refusal, executed: Read Only holds tickets.view and NOT
    // views.manage (its list is computed; the exclusion is physics).
    $refused = $this->actingAs($readOnly, 'staff')->postJson('https://localhost/inbox/views', ['name' => 'ro-write', 'filters' => ['status' => ['open']]]);
    expect($refused->status())->toBeGreaterThanOrEqual(400, 'SEV-1 again: Read Only wrote a view');

    // 2 — the AUDIENCE proof: the Junior Agent template creates a personal
    // view (tickets.edit-based mapping would have refused exactly this).
    $created = $this->actingAs($junior, 'staff')->postJson('https://localhost/inbox/views', ['name' => 'my follow-ups', 'filters' => ['status' => ['open']]]);
    $created->assertStatus(201);

    // 3 — Read Only still USES system views, read-only: the projector filed
    // the fixture ticket; the RO principal pages it through the scoped read.
    asTenant($tenant, function () use ($tenantId, $ticket, $conversation, $readOnly): void {
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
            'conversation_id' => $conversation->identifier(), 'customer_id' => $ticket->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
        $page = app(App\Domain\Inbox\ReadModels\ListReadModel::class)->page('unassigned', 'unassigned', $readOnly);
        // in_array, not toContain($x, $msg) — the S22 variadic trap, third dodge.
        expect(in_array($ticket->identifier(), array_column($page, 'ticket_id'), true))
            ->toBeTrue('Read Only lost its READ — the ruling narrowed writes only');
    });
});
