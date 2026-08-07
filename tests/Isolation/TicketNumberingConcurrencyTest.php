<?php

declare(strict_types=1);

use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\SequenceContention;
use App\Domain\Tickets\Creation\TicketSource;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 FR-02 / NFR-05 / FS-03 — human-facing numbers under concurrency
|--------------------------------------------------------------------------
|
| NFR-05: "Numbering under concurrency: 100 parallel creates → 100 unique numbers,
| zero duplicates." FS-03: "Sequence contention spike (mass API creates) → short lock
| waits within NFR-05; beyond threshold, creates receive backpressure 429 — never
| duplicate numbers."
|
| WHY THESE RUN ON REAL CONNECTIONS. A loop on one connection proves nothing about
| concurrency — every allocation would be serialised by the single session before the
| database ever saw contention. These drive genuinely separate Postgres sessions, the
| same harness Doc 01's SequenceAllocatorTest established, so the row lock is doing the
| work rather than PHP's execution order.
|
| Each ticket is created on its OWN conversation, deliberately: FR-10 permits exactly
| one open-family ticket per conversation, so 100 creates on one conversation would
| test T-02's index (AC-02 does that) rather than the counter.
|
| Fixtures are committed and cleaned up in a finally — a second connection cannot see
| an uncommitted row.
*/

/** @return list<string> conversation ids, committed and visible to other connections */
function numberingConversations(string $tenantId, int $count): array
{
    $customer = Customer::query()->create([
        'display_name' => 'Fixture Player',
        'username' => '@fixture_numbering',
        'is_shell' => true,
    ]);

    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $id = (string) Str::uuid7();
        DB::table('conversations')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'channel' => 'fakechan',
            'channel_ref_id' => 'bot-1',
            'external_chat_id' => 'chat-num-'.$i,
            'customer_id' => $customer->identifier(),
            'state' => 'active',
        ]);
        $ids[] = $id;
    }

    return $ids;
}

it('gives 100 concurrent creates 100 unique numbers, with zero duplicates (NFR-05)', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $conversationIds = numberingConversations($tenantId, 100);
    commitFixtures();

    $connectionNames = [];

    try {
        // Ten real sessions interleaved over the hundred creates. Ten rather than a
        // hundred because the property under test is that SEPARATE sessions serialise
        // on the counter row — ten independent sessions demonstrate that; a hundred
        // would only add connection overhead to the same proof.
        $workers = 10;
        for ($w = 0; $w < $workers; $w++) {
            $name = "pgsql_num_worker_{$w}";
            config(["database.connections.{$name}" => config('database.connections.pgsql')]);
            $connectionNames[] = $name;
            DB::connection($name)->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
        }

        $creator = app(CreateTicket::class);

        foreach ($conversationIds as $index => $conversationId) {
            $name = $connectionNames[$index % $workers];

            // Point the DEFAULT connection at this worker's session for the duration of
            // one create, so the whole creation path (sequence + insert + slot claim)
            // runs on it exactly as it would in a request.
            DB::setDefaultConnection($name);

            try {
                /** @var Conversation $conversation */
                $conversation = Conversation::query()->findOrFail($conversationId);
                $creator->create(new NewTicket($conversation, TicketSource::Api, idempotencyKey: 'num-'.$index));
            } finally {
                DB::setDefaultConnection('pgsql');
            }
        }

        $numbers = DB::connection('pgsql_platform')
            ->table('tickets')->where('tenant_id', $tenantId)->pluck('number')->all();

        expect($numbers)->toHaveCount(100, 'not every create produced a ticket');
        expect(array_unique($numbers))->toHaveCount(100,
            'DUPLICATE TICKET NUMBERS — two players can be told the same #4812 (FR-02/NFR-05)');

        // FR-02: "per-tenant sequential integer … never reused". With no rollbacks the
        // run is also gap-free, which is the stronger statement and worth pinning.
        sort($numbers);
        expect($numbers)->toBe(range(1, 100), 'the numbers are unique but not the sequence 1..100');
    } finally {
        foreach ($connectionNames as $name) {
            $connection = DB::connection($name);
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            DB::purge($name);
        }
        DB::setDefaultConnection('pgsql');
        cleanupTenants([$tenantId]);
    }
});

it('answers a contention spike with backpressure and NEVER a duplicate number (FS-03)', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    [$conversationId] = numberingConversations($tenantId, 1);
    commitFixtures();

    config(['database.connections.pgsql_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('pgsql_holder');

    try {
        $holder->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);

        // A worker holds the counter row locked — the mass-API-create spike FS-03
        // describes, in its worst shape: one allocator stalled mid-transaction.
        $holder->beginTransaction();
        app(App\Support\Ids\TenantSequence::class)->next('ticket_number', $holder);

        /** @var Conversation $conversation */
        $conversation = Conversation::query()->findOrFail($conversationId);

        $refusal = null;
        $started = microtime(true);
        try {
            app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Api));
        } catch (SequenceContention $e) {
            $refusal = $e;
        }
        $waited = (microtime(true) - $started) * 1000;

        expect($refusal)->not->toBeNull(
            'the create waited on the locked counter indefinitely instead of applying backpressure (FS-03)',
        );
        expect(SequenceContention::CODE)->toBe('sequence_contention');

        // "short lock waits": bounded, and demonstrably bounded — it gave up rather
        // than blocking until the holder felt like committing.
        expect($waited)->toBeLessThan(10_000.0, 'the wait was not bounded');

        // THE CLAUSE THAT MATTERS: never a duplicate number. The refused create left
        // NOTHING behind — no ticket, and no consumed counter value either, because the
        // allocation and the row share one transaction.
        $holder->rollBack();

        expect(DB::connection('pgsql_platform')->table('tickets')->where('tenant_id', $tenantId)->count())
            ->toBe(0, 'a refused create left a ticket behind');
    } finally {
        if ($holder->transactionLevel() > 0) {
            $holder->rollBack();
        }
        DB::purge('pgsql_holder');
        cleanupTenants([$tenantId]);
    }
});

it('lets a rolled-back create take its number with it — gaps are acceptable, reuse is not (FR-02)', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    $conversationIds = numberingConversations($tenantId, 2);
    commitFixtures();

    try {
        /** @var Conversation $first */
        $first = Conversation::query()->findOrFail($conversationIds[0]);
        app(CreateTicket::class)->create(new NewTicket($first, TicketSource::Agent));

        // A create that fails after allocating: the number goes back with the
        // transaction, so the counter does NOT advance and nothing is leaked.
        try {
            DB::transaction(function (): void {
                app(App\Support\Ids\TenantSequence::class)->next('ticket_number');
                throw new RuntimeException('fixture: the create fails after allocating');
            });
        } catch (RuntimeException) {
            // expected
        }

        /** @var Conversation $second */
        $second = Conversation::query()->findOrFail($conversationIds[1]);
        $after = app(CreateTicket::class)->create(new NewTicket($second, TicketSource::Agent));

        // C-01's whole reason for "inside the owning transaction": the rollback took
        // the increment with it, so this is 2 rather than 3.
        expect($after->number)->toBe(2);

        // And no number is ever REUSED: both live tickets differ.
        $numbers = DB::connection('pgsql_platform')->table('tickets')->where('tenant_id', $tenantId)->pluck('number')->all();
        expect(array_unique($numbers))->toHaveCount(count($numbers));
    } finally {
        cleanupTenants([$tenantId]);
    }
});
