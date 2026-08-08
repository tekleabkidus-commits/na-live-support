<?php

declare(strict_types=1);

use App\Domain\Tickets\Bulk\BulkOperation;
use App\Events\Conversations\RealtimePush;
use App\Jobs\Tickets\TicketBulkJob;
use App\Models\Role;
use App\Models\Ticket;
use App\Support\Rbac\CapabilityCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 AC-07 — bulk operations (step 7)
|--------------------------------------------------------------------------
|
| "AC-07 Bulk: mixed-permission batch yields exact per-item allow/deny; 200 items
| within NFR-04; progress events observed."
|
| SEC-04's no-averaging is STRUCTURAL (the job holds no verdicts — every item asks
| the owning operation, which asks the live provider), and EC-12 is its observable
| consequence: this file revokes the permission MID-BATCH, between item 2 and item 3,
| and the remaining items must fail with the honest per-item reason. Sabotage #4
| (cache the verdict before the loop) turns exactly that red.
|
*/

/** Run the queued executor the way the worker would, tenant already bound. */
function runBulkJob(string $jobId): void
{
    app()->call([new TicketBulkJob($jobId), 'handle']);
}

/** A raw closed-status ticket row — tags are legal on closed tickets (FR-07). */
function rawClosedTicket(string $tenantId, string $conversationId, string $customerId): string
{
    $id = (string) Str::uuid7();

    DB::table('tickets')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'conversation_id' => $conversationId,
        'customer_id' => $customerId,
        'number' => random_int(1, 2_000_000_000),
        'status' => 'closed',
        'priority' => 'normal',
        'source' => 'agent',
    ]);

    return $id;
}

test('AC-07: a mixed batch yields EXACT per-item allow/deny — the close over an open ticket fails THAT ITEM with illegal_transition', function () {
    [$tenant, $conversation] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $actor = ticketActor(['tickets.close', 'tickets.view'], 'Fixture Bulk Role');

    // Two resolved (legal close) around one open (illegal): order matters — the
    // failure must not stop the batch (per-item partial success, no all-or-nothing).
    $resolvedA = machineClosedTicket(anotherConversation($tenantId, 'bulk-a'), 'resolved');
    $openB = machineClosedTicket(anotherConversation($tenantId, 'bulk-b'), 'open');
    $resolvedC = machineClosedTicket(anotherConversation($tenantId, 'bulk-c'), 'resolved');

    Queue::fake();
    $jobId = app(BulkOperation::class)->submit($actor, 'close', [
        $resolvedA->identifier(), $openB->identifier(), $resolvedC->identifier(),
    ]);

    Event::fake([RealtimePush::class]);
    runBulkJob($jobId);

    expect($resolvedA->fresh()->status()->value)->toBe('closed')
        ->and($openB->fresh()->status()->value)->toBe('open', 'the illegal item must fail ALONE — its neighbours close')
        ->and($resolvedC->fresh()->status()->value)->toBe('closed');

    /** @var list<object{position: int, status: string, failure_reason: string|null}> $items */
    $items = DB::table('ticket_bulk_job_items')->where('bulk_job_id', $jobId)->orderBy('position')->get()->all();
    expect(array_map(static fn (object $i): array => [$i->status, $i->failure_reason], $items))
        ->toBe([['ok', null], ['failed', 'illegal_transition'], ['ok', null]], 'AC-07: exact per-item results, in submission order');

    // FR-25's one summary: {ok, failed[]} with the stable reason.
    $summary = json_decode((string) DB::table('outbox')->where('name', 'ticket.bulk_completed')->first()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($summary['ok'])->toBe(2)
        // toEqual: jsonb canonicalizes key order inside each element; the VALUES are
        // exact strings, so loose comparison risks nothing here.
        ->and($summary['failed'])->toEqual([['ticket_id' => $openB->identifier(), 'reason' => 'illegal_transition']])
        ->and($summary['action'])->toBe('close');

    // NFR-04: per-item progress over Reverb user.{requester}, through Doc 04's door.
    Event::assertDispatchedTimes(RealtimePush::class, 3);
    Event::assertDispatched(RealtimePush::class, function (RealtimePush $push) use ($tenantId, $actor): bool {
        return $push->channelName === sprintf('tenant.%s.user.%s', $tenantId, $actor->identifier())
            && $push->eventName === 'bulk.progress';
    });

    // SEC-03: the bulk summary is audited, on the job row.
    expect(DB::table('audit_log')->where('action_key', 'ticket.bulk_completed')->where('entity_id', $jobId)->count())->toBe(1)
        ->and(DB::table('ticket_bulk_jobs')->where('id', $jobId)->value('status'))->toBe('completed');
});

test('EC-12/SEC-04: a permission revoked MID-BATCH fails the remaining items — the check is live at execution, never a snapshot', function () {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $actor = ticketActor(['tickets.close', 'tickets.view'], 'Fixture Revocable Role');

    $tickets = [];
    foreach (range(1, 4) as $i) {
        $tickets[] = machineClosedTicket(anotherConversation($tenantId, 'ec12-'.$i), 'resolved');
    }

    Queue::fake();
    $jobId = app(BulkOperation::class)->submit($actor, 'close', array_map(
        static fn (Ticket $t): string => $t->identifier(),
        $tickets,
    ));

    // THE REVOCATION, between item 2 and item 3: fired by the 2nd item-row update,
    // exactly as a live admin action would land mid-run. The guard flag keeps the
    // listener from recursing on its own queries.
    $completed = 0;
    $revoking = false;
    DB::listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$completed, &$revoking, $actor): void {
        if ($revoking || ! str_contains($query->sql, 'ticket_bulk_job_items') || ! str_starts_with(strtolower(trim($query->sql)), 'update')) {
            return;
        }

        $completed++;

        if ($completed === 2) {
            $revoking = true;
            Role::query()->where('name', 'Fixture Revocable Role')
                ->update(['permissions' => json_encode(['tickets.view'])]);
            app(CapabilityCacheInvalidator::class)->forgetUser($actor);
            $revoking = false;
        }
    });

    runBulkJob($jobId);

    /** @var list<object{status: string, failure_reason: string|null}> $items */
    $items = DB::table('ticket_bulk_job_items')->where('bulk_job_id', $jobId)->orderBy('position')->get()->all();
    expect(array_map(static fn (object $i): array => [$i->status, $i->failure_reason], $items))
        ->toBe([
            ['ok', null],
            ['ok', null],
            ['failed', 'transition_not_permitted'],
            ['failed', 'transition_not_permitted'],
        ], 'EC-12: items after the revocation must fail with the live answer — a cached verdict would close all four');

    expect($tickets[0]->fresh()->status()->value)->toBe('closed')
        ->and($tickets[3]->fresh()->status()->value)->toBe('resolved', 'the revoked half must not have executed');
});

test('AC-07: a 200-item batch completes with 200 per-item progress events and an ok=200 summary', function () {
    [$tenant, $conversation] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $customerId = $conversation->customer_id;
    $actor = ticketActor(['tickets.tags.manage', 'tickets.view'], 'Fixture Bulk Tag Role');

    // 200 closed tickets in ONE conversation: closed is outside the open family, so
    // T-02 admits them all, and tags are legal on closed tickets (FR-07).
    $ids = [];
    foreach (range(1, BulkOperation::MAX_ITEMS) as $i) {
        $ids[] = rawClosedTicket($tenantId, $conversation->identifier(), $customerId);
    }

    Queue::fake();
    $jobId = app(BulkOperation::class)->submit($actor, 'tag', $ids, ['tags' => ['bulk-sweep']]);

    Event::fake([RealtimePush::class]);
    $startedAt = microtime(true);
    runBulkJob($jobId);
    $elapsed = microtime(true) - $startedAt;

    Event::assertDispatchedTimes(RealtimePush::class, 200);

    $summary = json_decode((string) DB::table('outbox')->where('name', 'ticket.bulk_completed')->first()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($summary['ok'])->toBe(200)->and($summary['failed'])->toBe([]);

    expect(DB::table('tickets')->whereIn('id', $ids)->whereRaw("'bulk-sweep' = ANY(tags)")->count())->toBe(200);

    // NFR-04's ≤30 s p95 is §12's ENFORCED budget (perf structure, step 8); this
    // run's wall clock is recorded as dev-hardware-indicative, not asserted.
    fwrite(STDERR, sprintf("\n[NFR-04 indicative] 200-item tag batch: %.2fs\n", $elapsed));

    // A re-driven COMPLETED job re-runs nothing and re-publishes nothing (C-09).
    runBulkJob($jobId);
    expect(DB::table('outbox')->where('name', 'ticket.bulk_completed')->count())->toBe(1);
});

test('FR-25: submit refuses an unknown action, an over-cap batch, unknown tickets — and dedupes the selection', function () {
    [$tenant, , $ticket] = ticketFixture('open');
    $actor = ticketActor(['tickets.view'], 'Fixture Submit Role');

    expect(fn () => app(BulkOperation::class)->submit($actor, 'delete', [$ticket->identifier()]))
        ->toThrow(DomainException::class, 'unknown bulk action');

    expect(fn () => app(BulkOperation::class)->submit($actor, 'tag', array_map(
        static fn (): string => (string) Str::uuid7(),
        range(1, 201),
    ), ['tags' => ['x']]))->toThrow(DomainException::class, 'caps a batch at 200');

    expect(fn () => app(BulkOperation::class)->submit($actor, 'tag', [(string) Str::uuid7()], ['tags' => ['x']]))
        ->toThrow(DomainException::class, 'do not exist');

    Queue::fake();
    $jobId = app(BulkOperation::class)->submit($actor, 'tag', [
        $ticket->identifier(), $ticket->identifier(), $ticket->identifier(),
    ], ['tags' => ['deduped']]);

    expect(DB::table('ticket_bulk_jobs')->where('id', $jobId)->value('total'))->toBe(1, 'a double-clicked selection is one item, not three');
});
