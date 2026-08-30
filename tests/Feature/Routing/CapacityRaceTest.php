<?php

declare(strict_types=1);

use App\Domain\Tickets\Assignment\AssignTicket;
use App\Domain\Tickets\Assignment\AtCapacity;
use App\Domain\Tickets\Assignment\RoutingCommit;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-05 / FR-06 / EC-01 / EC-03 / AC-03 — CAPACITY IS SERIALIZED AT COMMIT
|--------------------------------------------------------------------------
|
| AC-03: "race serialization (EC-01/03) under concurrent-load test with zero
| over-capacity assignments." Three proofs:
|
|  1. THE COUNT: capacity C, C+2 tickets routed to one agent → exactly C succeed, the
|     rest AtCapacity, and the refused tickets' rows are UNTOUCHED (assignee null).
|  2. THE LOCK: a second database session holds the agent's stats row FOR UPDATE; the
|     assignment path BLOCKS on it (lock_timeout fires) instead of reading a stale
|     count — the serialization is the database's, not a read's.
|  3. FR-06: pending_customer does not consume a slot unless routing.capacity.count_pending;
|     the per-user override (routing.capacity.user) outranks the queue capacity (US-07).
|
| Sabotage #4 of the S11 plan drops the FOR UPDATE check → proof 1 admits C+1.
*/

/**
 * N sibling conversations IN THE SAME TENANT (creationFixture() mints a new tenant per
 * call — the first run's own catch: an agent is out of scope of every other tenant's
 * ticket), each with one `new` ticket, plus an agent of that tenant.
 *
 * @return array{0: list<Ticket>, 1: User}
 */
function capacityFixture(int $tickets): array
{
    [$tenant, $first] = creationFixture();
    $agent = ticketActor(['tickets.view'], 'Fixture Capacity Agent');
    $list = [app(CreateTicket::class)->create(new NewTicket($first, TicketSource::Agent))];
    for ($i = 1; $i < $tickets; $i++) {
        $conversationId = (string) Str::uuid7();
        DB::table('conversations')->insert([
            'id' => $conversationId,
            'tenant_id' => $tenant->identifier(),
            'channel' => 'fakechan',
            'channel_ref_id' => 'bot-1',
            'external_chat_id' => 'chat-'.Str::random(10),
            'customer_id' => $first->customer_id,
            'state' => 'active',
        ]);
        /** @var App\Models\Conversation $conversation */
        $conversation = App\Models\Conversation::query()->findOrFail($conversationId);
        $list[] = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent));
    }

    return [$list, $agent];
}

function commitFor(int $capacity, bool $countPending = false): RoutingCommit
{
    return new RoutingCommit((string) Str::uuid7(), (string) Str::uuid7(), $capacity, $countPending);
}

it('AC-03 (1): capacity C admits exactly C assignments; the C+1th and C+2nd are AtCapacity with their rows untouched', function () {
    $capacity = 3;
    [$tickets, $agent] = capacityFixture($capacity + 2);

    $outcomes = [];
    foreach ($tickets as $ticket) {
        try {
            app(AssignTicket::class)->assignBySystem($ticket, $agent, commitFor($capacity));
            $outcomes[] = 'assigned';
        } catch (AtCapacity $e) {
            $outcomes[] = 'at_capacity';
            expect($e->capacity)->toBe($capacity)->and($e->openAssigned)->toBe($capacity);
        }
    }

    expect(array_count_values($outcomes))->toBe(['assigned' => $capacity, 'at_capacity' => 2]);
    $assigned = DB::table('tickets')->whereIn('id', array_map(fn ($t) => $t->identifier(), $tickets))->whereNotNull('assignee_id')->count();
    expect($assigned)->toBe($capacity, 'over-capacity assignment happened');
    expect((int) DB::table('agent_assignment_stats')->where('user_id', $agent->identifier())->value('open_assigned'))->toBe($capacity);
});

it('AC-03 (2): the commit path WAITS on the stats row lock held by another session — serialization is the database\'s', function () {
    // Cross-session locks need COMMITTED rows (the LaneOrderingSoakTest precedent): the
    // first run of this proof found the platform session locking ZERO rows because the
    // stats row lived only inside the test's wrapping transaction — so this test commits
    // its fixtures and cleans up after itself.
    [$tickets, $agent] = capacityFixture(2);
    $tenantId = (string) $tickets[0]->getAttribute('tenant_id');
    app(AssignTicket::class)->assignBySystem($tickets[0], $agent, commitFor(5));
    commitFixtures();
    DB::connection('pgsql')->beginTransaction();

    // A SECOND session: the platform connection (ADR 0003's BYPASSRLS role) is only here to HOLD the lock.
    $other = DB::connection('pgsql_platform');
    $other->beginTransaction();
    /** @var list<object> $locked */
    $locked = $other->select('SELECT id FROM agent_assignment_stats WHERE user_id = ? FOR UPDATE', [$agent->identifier()]);
    expect($locked)->toHaveCount(1, 'the lock holder saw no committed stats row — the proof would be vacuous');

    try {
        DB::statement("SET lock_timeout = '400ms'");
        $started = microtime(true);
        try {
            app(AssignTicket::class)->assignBySystem($tickets[1], $agent, commitFor(5));
            $this->fail('the assignment did not wait on the lock — it read a stale count');
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('lock timeout');
            expect(microtime(true) - $started)->toBeGreaterThan(0.35);
        }
    } finally {
        DB::statement("SET lock_timeout = '0'");
        $other->rollBack();
        if (DB::connection('pgsql')->transactionLevel() > 0) {
            DB::connection('pgsql')->rollBack();
        }
        expect(DB::connection('pgsql_platform')->table('tickets')->where('id', $tickets[1]->identifier())->value('assignee_id'))->toBeNull();
        cleanupTenants([$tenantId]);
        DB::connection('pgsql')->beginTransaction(); // leave the harness the transaction it expects to roll back
    }
});

it('FR-06: pending_customer does not consume a slot unless count_pending; the per-user override outranks the queue capacity (US-07)', function () {
    [$tickets, $agent] = capacityFixture(5);
    app(AssignTicket::class)->assignBySystem($tickets[0], $agent, commitFor(1));
    // The agent's one ticket goes pending_customer (the step-4 consumer's bookkeeping, done directly here).
    DB::table('agent_assignment_stats')->where('user_id', $agent->identifier())->update(['pending_customer_assigned' => 1]);

    // Capacity 1, one pending: a slot is FREE when pending is excluded…
    app(AssignTicket::class)->assignBySystem($tickets[1], $agent, commitFor(1, countPending: false));
    // …and the same state with count_pending would have refused (a third ticket proves it).
    expect(fn () => app(AssignTicket::class)->assignBySystem($tickets[2], $agent, commitFor(2, countPending: true)))
        ->toThrow(AtCapacity::class);

    // US-07: the per-user override (trainee = 1) outranks a queue capacity of 5 — same tenant, a second agent.
    $t2 = [$tickets[3], $tickets[4]];
    $trainee = ticketActor(['tickets.view'], 'Fixture Trainee Agent');
    DB::table('user_routing_capacity')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => app(App\Support\Tenancy\TenantContext::class)->tenantId(),
        'user_id' => $trainee->identifier(), 'capacity' => 1, 'set_by' => $trainee->identifier(), 'reason' => 'trainee',
    ]);
    app(AssignTicket::class)->assignBySystem($t2[0], $trainee, commitFor(5));
    expect(fn () => app(AssignTicket::class)->assignBySystem($t2[1], $trainee, commitFor(5)))->toThrow(AtCapacity::class);
});
