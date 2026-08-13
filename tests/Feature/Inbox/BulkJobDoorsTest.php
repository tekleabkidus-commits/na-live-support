<?php

declare(strict_types=1);

use App\Jobs\Tickets\TicketBulkJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Unit D (S07 step 14) — C15/C16's server seam: the id-based bulk doors.
| store() is the door the drawn bar's PARTIAL selections require (the token
| door executes whole views by design); index() is the drawer's poll;
| cancel() is C15's Stop, observed LIVE between items; retry() re-batches
| exactly the failed rows. Queue is sync under test, so a submitted batch
| has already run by the next line — the door assertions read final truth.
|--------------------------------------------------------------------------
*/

/** @return array{App\Models\Tenant, string, list<App\Models\Ticket>} */
function bulkDoorScene(int $tickets, string $status = 'resolved'): array
{
    [$tenant, $conversation, $ticket] = ticketFixture($status);
    $tenantId = $tenant->identifier();
    $rows = [$ticket];

    for ($i = 2; $i <= $tickets; $i++) {
        $c = anotherConversation($tenantId, "bulkd-{$i}");
        [$t] = inboxTicketRow($tenantId, $c, $status);
        $rows[] = $t;
    }

    return [$tenant, $tenantId, $rows];
}

function bulkDoorActor(string $name): User
{
    $actor = inboxScopedActor('All', $name);

    return grantPermissions($actor, ['tickets.view', 'conversations.view', 'views.manage', 'tickets.close'], $name.' Role');
}

it('the id-based submit door: "3 selected · Close" executes through POST /inbox/bulk — the batch runs, the progress read reports done with zero failures, tickets really closed, and the wide mutation is audited (inbox.bulk_submitted)', function (): void {
    [$tenant, $tenantId, $rows] = bulkDoorScene(3, 'resolved');
    activateTenant($tenant);
    $actor = bulkDoorActor('S07 BulkD Submit');

    $ids = array_map(static fn (App\Models\Ticket $t): string => $t->identifier(), $rows);

    $submitted = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/bulk', [
        'action' => 'close',
        'ticket_ids' => $ids,
    ]);
    $submitted->assertStatus(201);
    expect($submitted->json('resolved'))->toBe(3)
        ->and($submitted->json('skipped'))->toBe(0)
        ->and($submitted->json('bulk_job_ids'))->toHaveCount(1);

    /** @var string $jobId */
    $jobId = $submitted->json('bulk_job_ids.0');

    // Sync queue: the batch already ran — the drawer's poll reads final truth.
    $progress = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/bulk-jobs?ids[]='.$jobId);
    $progress->assertOk();
    expect($progress->json('jobs.0.status'))->toBe('completed')
        ->and($progress->json('jobs.0.total'))->toBe(3)
        ->and($progress->json('jobs.0.ok'))->toBe(3)
        ->and($progress->json('jobs.0.done'))->toBe(3)
        ->and($progress->json('jobs.0.failed'))->toBe([])
        ->and($progress->json('jobs.0.cancel_requested'))->toBeFalse();

    asTenant($tenant, function () use ($ids): void {
        expect(DB::table('tickets')->whereIn('id', $ids)->where('status', 'closed')->count())->toBe(3, 'the batch claimed ok but tickets did not close');
        expect(DB::table('audit_log')->where('action_key', 'inbox.bulk_submitted')->count())->toBeGreaterThanOrEqual(1, 'the id-based submit is a wide mutation and must audit');
    });
});

it('C15 failure rows + retry: a failed item is named by TICKET NUMBER with its stable reason; "Retry the N that failed" mints a NEW batch over exactly the failed tickets; a fully-ok job refuses retry (nothing_to_retry)', function (): void {
    // Two resolved (close is legal) around one open (open→closed is illegal —
    // the same edge BulkOperationsTest AC-07 proves), so exactly one row fails.
    [$tenant, $tenantId, $rows] = bulkDoorScene(2, 'resolved');
    activateTenant($tenant);
    $open = anotherConversation($tenantId, 'bulkd-open');
    [$openTicket] = inboxTicketRow($tenantId, $open, 'open');
    $actor = bulkDoorActor('S07 BulkD Retry');

    $ids = [...array_map(static fn (App\Models\Ticket $t): string => $t->identifier(), $rows), $openTicket->identifier()];

    $submitted = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/bulk', [
        'action' => 'close',
        'ticket_ids' => $ids,
    ]);
    $submitted->assertStatus(201);
    /** @var string $jobId */
    $jobId = $submitted->json('bulk_job_ids.0');

    $progress = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/bulk-jobs?ids[]='.$jobId);
    expect($progress->json('jobs.0.ok'))->toBe(2)
        ->and($progress->json('jobs.0.failed_count'))->toBe(1)
        ->and($progress->json('jobs.0.failed'))->toBe([
            ['number' => (int) $openTicket->getAttribute('number'), 'reason' => 'illegal_transition'],
        ], 'the drawn drawer names the failed row by number and reason — the agent never guesses');

    // Retry re-batches EXACTLY the failed ticket (it fails again — the door's
    // mechanics are what is proven, not the ticket's fate).
    $retried = $this->actingAs($actor, 'staff')->postJson("https://localhost/inbox/bulk-jobs/{$jobId}/retry");
    $retried->assertStatus(201);
    expect($retried->json('resolved'))->toBe(1)->and($retried->json('bulk_job_ids'))->toHaveCount(1);

    /** @var string $retryJobId */
    $retryJobId = $retried->json('bulk_job_ids.0');
    asTenant($tenant, function () use ($retryJobId, $openTicket): void {
        expect((int) DB::table('ticket_bulk_jobs')->where('id', $retryJobId)->value('total'))->toBe(1)
            ->and(DB::table('ticket_bulk_job_items')->where('bulk_job_id', $retryJobId)->pluck('ticket_id')->all())->toBe([$openTicket->identifier()]);
    });

    // A batch with nothing failed refuses the retry with the stable shape.
    $fresh = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/bulk', [
        'action' => 'close',
        'ticket_ids' => [$ids[0]], // already closed → same-state noop success (EC-07)
    ]);
    $fresh->assertStatus(201);
    $refused = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/bulk-jobs/'.$fresh->json('bulk_job_ids.0').'/retry');
    $refused->assertStatus(422);
});

it('C15\'s Stop: the running loop OBSERVES the latch before each item — a pre-latched batch runs NOTHING (every item finishes failed(cancelled), tickets untouched); the door refuses a stop on a finished batch (409) and a foreign requester gets the 404 pattern', function (): void {
    [$tenant, $tenantId, $rows] = bulkDoorScene(2, 'resolved');
    activateTenant($tenant);
    $actor = bulkDoorActor('S07 BulkD Stop');
    $colleague = bulkDoorActor('S07 BulkD Stop Colleague');

    // (a) The observation proof: rows built by hand with the latch ALREADY
    // set, then the real job runs — under sync there is no between-items
    // window, so the pre-latched batch is the honest executable form of
    // "cancel observed before the next item".
    $jobId = (string) Str::uuid7();
    asTenant($tenant, function () use ($jobId, $tenantId, $actor, $rows): void {
        DB::table('ticket_bulk_jobs')->insert([
            'id' => $jobId,
            'tenant_id' => $tenantId,
            'requested_by' => $actor->identifier(),
            'action' => 'close',
            'params' => null,
            'total' => count($rows),
            'cancel_requested_at' => DB::raw('clock_timestamp()'),
        ]);
        foreach ($rows as $position => $ticket) {
            DB::table('ticket_bulk_job_items')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'bulk_job_id' => $jobId,
                'ticket_id' => $ticket->identifier(),
                'position' => $position,
            ]);
        }
    });

    asTenant($tenant, function () use ($jobId): void {
        app()->call([new TicketBulkJob($jobId), 'handle']);
    });

    asTenant($tenant, function () use ($jobId, $rows): void {
        expect(DB::table('ticket_bulk_jobs')->where('id', $jobId)->value('status'))->toBe('completed')
            ->and(DB::table('ticket_bulk_job_items')->where('bulk_job_id', $jobId)->pluck('failure_reason')->unique()->all())->toBe(['cancelled'], 'items that never ran must finish failed(cancelled)');
        $ids = array_map(static fn (App\Models\Ticket $t): string => $t->identifier(), $rows);
        expect(DB::table('tickets')->whereIn('id', $ids)->where('status', 'resolved')->count())->toBe(count($rows), 'a cancelled batch must run NOTHING');
    });

    // (b) Stop on the finished batch: nothing left to stop — 409.
    $late = $this->actingAs($actor, 'staff')->postJson("https://localhost/inbox/bulk-jobs/{$jobId}/cancel");
    $late->assertStatus(409);

    // (c) Requester binding: the colleague's read omits the job silently and
    // their stop answers the 404 pattern (C-10 — no existence probing).
    $foreignRead = $this->actingAs($colleague, 'staff')->getJson('https://localhost/inbox/bulk-jobs?ids[]='.$jobId);
    $foreignRead->assertOk();
    expect($foreignRead->json('jobs'))->toBe([]);
    $foreignStop = $this->actingAs($colleague, 'staff')->postJson("https://localhost/inbox/bulk-jobs/{$jobId}/cancel");
    $foreignStop->assertStatus(404);

    // (d) A stoppable batch takes the latch through the door and audits it.
    $stoppableId = (string) Str::uuid7();
    asTenant($tenant, function () use ($stoppableId, $tenantId, $actor, $rows): void {
        DB::table('ticket_bulk_jobs')->insert([
            'id' => $stoppableId,
            'tenant_id' => $tenantId,
            'requested_by' => $actor->identifier(),
            'action' => 'close',
            'params' => null,
            'total' => 1,
            'status' => 'running',
        ]);
        DB::table('ticket_bulk_job_items')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'bulk_job_id' => $stoppableId,
            'ticket_id' => $rows[0]->identifier(),
            'position' => 0,
        ]);
    });
    $stopped = $this->actingAs($actor, 'staff')->postJson("https://localhost/inbox/bulk-jobs/{$stoppableId}/cancel");
    $stopped->assertOk();
    asTenant($tenant, function () use ($stoppableId): void {
        expect(DB::table('ticket_bulk_jobs')->where('id', $stoppableId)->value('cancel_requested_at'))->not->toBeNull()
            ->and(DB::table('audit_log')->where('action_key', 'inbox.bulk_stop_requested')->count())->toBeGreaterThanOrEqual(1);
    });
});

it('C16\'s consequence line is SERVER truth: mint returns open_windows counted in DB time over the view\'s CURRENT members — the client never derives window state', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $tickets = [$ticket];
    $project = function (App\Models\Ticket $t, App\Models\Conversation $c) use ($tenantId): void {
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    };
    $project($ticket, $conversation);
    for ($i = 2; $i <= 4; $i++) {
        $c = anotherConversation($tenantId, "bulkw-{$i}");
        [$t] = inboxTicketRow($tenantId, $c, 'open');
        $tickets[] = $t;
        $project($t, $c);
    }
    activateTenant($tenant);
    $actor = bulkDoorActor('S07 BulkD Windows');

    // Window truth on the read model: two open (future), one lapsed, one null.
    asTenant($tenant, function () use ($tickets): void {
        DB::table('ticket_list_items')->where('ticket_id', $tickets[0]->identifier())
            ->update(['window_expires_at' => DB::raw("clock_timestamp() + interval '2 hours'")]);
        DB::table('ticket_list_items')->where('ticket_id', $tickets[1]->identifier())
            ->update(['window_expires_at' => DB::raw("clock_timestamp() + interval '30 minutes'")]);
        DB::table('ticket_list_items')->where('ticket_id', $tickets[2]->identifier())
            ->update(['window_expires_at' => DB::raw("clock_timestamp() - interval '1 hour'")]);
    });

    $minted = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/selections', ['view_key' => 'unassigned']);
    $minted->assertStatus(201);
    expect($minted->json('count'))->toBe(4)
        ->and($minted->json('open_windows'))->toBe(2, 'the consequence line must count only windows still open in DB time');
});
