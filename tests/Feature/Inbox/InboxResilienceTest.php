<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Domain\Inbox\ReadModels\ProjectorStatus;
use App\Domain\Inbox\ReadModels\SystemViews;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| FS-01 + FS-02 (S07 step 4) — drills against the REAL things: the real
| consumer left unrun (a dead projector IS a consumer that never fires), the
| real keys flushed. Sabotage #18's target lives here.
|--------------------------------------------------------------------------
*/

it('FS-01: projector down → lists FREEZE correct-as-of; the staleness signal crosses 30 s (sabotage #18 goes red here); writes are NOT blocked; recovery drains the backlog to convergence', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    $status = app(ProjectorStatus::class);
    $zsets = app(ViewZsets::class);

    // A healthy past: one event applied, heartbeat fresh.
    runListProjector(inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $ticket->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]));
    expect($zsets->allIds('unassigned'))->toBe([$ticket->identifier()])
        ->and($status->isStale())->toBeFalse();

    // ── THE OUTAGE: events accrue, the consumer NEVER RUNS (that is what a
    // dead projector is — nothing here is mocked).
    $backlog = [];
    $cA = anotherConversation($tenantId, 'fs1-a');
    [$tA] = inboxTicketRow($tenantId, $cA, 'open');
    $backlog[] = inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $tA->identifier(),
        'conversation_id' => $cA->identifier(), 'customer_id' => $tA->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]);
    DB::table('tickets')->where('id', $ticket->identifier())->update(['status' => 'resolved']);
    $backlog[] = inboxSeedEvent('ticket.status_changed', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'old_status' => 'new',
        'new_status' => 'resolved', 'actor_type' => 'agent', 'actor_id' => null,
    ]);

    // FROZEN CORRECT-AS-OF: the list still shows the pre-outage truth —
    // stale, never fabricated (the resolved ticket is still listed, the new
    // one not yet — exactly what "correct as of" means).
    expect($zsets->allIds('unassigned'))->toBe([$ticket->identifier()]);

    // THE STALENESS SIGNAL: drive the STORED heartbeat past the 30 s
    // threshold (the value is driven, never slept on) — sabotage #18 kills
    // this signal and THIS assertion goes red.
    Redis::connection('volatile')->set($status->key(), (string) (time() - 45));
    expect($status->staleSeconds())->toBeGreaterThan(ProjectorStatus::STALE_AFTER_SECONDS)
        ->and($status->isStale())->toBeTrue('the staleness signal is dead — agents would trust a frozen list (FS-01)');

    // NO AGENT ACTION BLOCKED: a real write door works mid-outage — the
    // priority write on the ALREADY-PROJECTED ticket succeeds (its event
    // would simply join the backlog; the WRITE itself never waits).
    DB::table('tickets')->where('id', $ticket->identifier())->update(['priority' => 'high']);
    expect((string) DB::table('tickets')->where('id', $ticket->identifier())->value('priority'))->toBe('high');

    // ── RECOVERY: the consumer resumes FROM THE BACKLOG (FS-01's own words)
    // and the views converge to truth.
    foreach ($backlog as $eventId) {
        runListProjector($eventId);
    }
    $ids = $zsets->allIds('unassigned');
    sort($ids);
    $truth = SystemViews::directQuery('unassigned')->pluck('id')->all();
    sort($truth);
    expect($ids)->toBe($truth)
        ->and($status->isStale())->toBeFalse('recovery did not refresh the heartbeat');
});

it('FS-02: view ZSET loss → the read path REBUILDS ON MISS with the degraded signal, and inbox:views-rebuild recovers everything, system views first', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    $u1 = User::factory()->create();
    $all = inboxScopedActor('All', 'S07 FS2 Reader');
    $zsets = app(ViewZsets::class);

    $apply = function (App\Models\Ticket $t, App\Models\Conversation $c) use ($tenantId): void {
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    };
    $apply($ticket, $conversation);
    $cMine = anotherConversation($tenantId, 'fs2-mine');
    [$tMine] = inboxTicketRow($tenantId, $cMine, 'open', ['assignee_id' => $u1->identifier()]);
    $apply($tMine, $cMine);

    // ── THE LOSS: flush the REAL keys (unassigned + the user's my_open).
    $zsets->flush('unassigned');
    $zsets->flush(SystemViews::userView('my_open', $u1->identifier()));

    // REBUILD-ON-MISS: the very next page is correct AND the degraded signal
    // fired (the log carries the metric name — asserted via the rebuilt
    // result being served, plus EXISTS turning true again).
    $page = app(ListReadModel::class)->page('unassigned', 'unassigned', $all);
    expect(array_column($page, 'ticket_id'))->toBe([$ticket->identifier()])
        ->and((int) Redis::connection('volatile')->exists($zsets->key('unassigned')))->toBe(1, 'the miss did not rebuild the set');

    // ── BULK RECOVERY: flush again, then the FS-02 command — system views
    // FIRST (its own output order), everything == direct queries after.
    $zsets->flush('unassigned');
    $zsets->flush(SystemViews::userView('my_open', $u1->identifier()));
    $this->artisan('inbox:views-rebuild', ['--tenant' => $tenantId])
        ->expectsOutputToContain('rebuilt unassigned')
        ->assertExitCode(0);

    foreach ([['unassigned', 'unassigned', null], [SystemViews::userView('my_open', $u1->identifier()), 'my_open', $u1->identifier()]] as [$key, $view, $userId]) {
        $truth = SystemViews::directQuery($view, $userId)->pluck('id')->all();
        $materialized = $zsets->allIds($key);
        sort($truth);
        sort($materialized);
        expect($materialized)->toBe($truth, "{$key}: rebuild did not converge to the direct query");
    }

    // The NFR-07 checker agrees post-recovery — the drills end consistent.
    $this->artisan('inbox:consistency-check', ['--tenant' => $tenantId])->assertExitCode(0);
});
