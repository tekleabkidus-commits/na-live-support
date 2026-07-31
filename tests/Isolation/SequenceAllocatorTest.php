<?php

declare(strict_types=1);

use App\Support\Ids\TenantSequence;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * T-02 / C-01 — per-tenant counters allocate via UPDATE … RETURNING in the owning
 * transaction: no duplicates under concurrency, no gaps under rollback.
 *
 * The duplicate case is the one worth being paranoid about: a duplicated ticket number is
 * two customers holding the same reference. The gap case is cosmetic by comparison, but
 * C-01 gets it free from transactionality, so it is proven too.
 */
it('allocates 1, 2, 3 … per (tenant, name), and tenants never share a counter', function () {
    $a = createTenant();
    $allocator = app(TenantSequence::class);

    expect($allocator->next('ticket_number'))->toBe(1)
        ->and($allocator->next('ticket_number'))->toBe(2)
        // A different NAME is a different counter for the same tenant.
        ->and($allocator->next('invoice_number'))->toBe(1);

    // A different TENANT starts from 1 in its own counter — numbers are per-tenant
    // (C-01), so tenant B observing "3" would mean it can infer tenant A's volume.
    createTenant();
    expect($allocator->next('ticket_number'))->toBe(1);

    // And A's counter was not disturbed by B's allocation.
    expect(asTenant($a, fn (): int => $allocator->next('ticket_number')))->toBe(3);
});

it('rolls the counter back with the owning transaction, so the sequence has no gaps', function () {
    createTenant();
    $allocator = app(TenantSequence::class);

    expect($allocator->next('ticket_number'))->toBe(1);

    // The "ticket failed to commit" case: allocate inside a nested transaction that
    // rolls back. Without the owning-transaction rule this would leak number 2 forever.
    try {
        DB::transaction(function () use ($allocator): void {
            expect($allocator->next('ticket_number'))->toBe(2);

            throw new RuntimeException('ticket creation failed after allocating');
        });
    } catch (RuntimeException) {
        // expected
    }

    // The next successful allocation gets the SAME number the failed one briefly held.
    expect($allocator->next('ticket_number'))->toBe(2);
});

it('allocates in a single atomic statement — no app-side read exists to go stale', function () {
    // The one race this harness cannot stage directly: a worker that SELECTs the counter,
    // computes app-side, and writes an absolute value back. Its stale read happens INSIDE
    // the method, between two statements, where a single-threaded test cannot interleave.
    // So the property is asserted structurally instead: allocation issues no SELECT against
    // tenant_sequences at all. If there is no read, there is no read to go stale.
    createTenant();
    $allocator = app(TenantSequence::class);
    $allocator->next('ticket_number'); // first call creates the counter row

    DB::flushQueryLog();
    DB::enableQueryLog();
    $allocator->next('ticket_number');
    $queries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $q): bool => str_contains($q, 'tenant_sequences'));
    DB::disableQueryLog();

    expect($queries)->not->toBeEmpty();
    expect(
        $queries->filter(fn (string $q): bool => str_starts_with(strtolower(ltrim($q)), 'select')),
    )->toBeEmpty('the allocator read the counter with a SELECT — that read can go stale under concurrency');
});

it('refuses to allocate outside a transaction (C-01: the owning transaction)', function () {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();
    commitFixtures();

    // A fresh connection with NO transaction open — the state a lazy caller would be in.
    config(['database.connections.pgsql_naked' => config('database.connections.pgsql')]);
    $naked = DB::connection('pgsql_naked');

    try {
        $naked->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);

        expect(fn () => app(TenantSequence::class)->next('ticket_number', $naked))
            ->toThrow(RuntimeException::class, 'OWNING transaction');
    } finally {
        DB::purge('pgsql_naked');
        cleanupTenants([$tenantId]);
    }
});

it('throws MissingTenantContext when no tenant is bound', function () {
    app(TenantContext::class)->clear();

    expect(fn () => app(TenantSequence::class)->next('ticket_number'))
        ->toThrow(MissingTenantContext::class);
});

/**
 * T-02 proper: two genuinely concurrent allocators. The row lock the UPDATE takes must
 * serialise them — the second worker BLOCKS rather than reading a stale counter.
 */
it('serialises two concurrent allocators on the row lock: no duplicate, no stale read', function () {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();
    commitFixtures();

    config(['database.connections.pgsql_worker_a' => config('database.connections.pgsql')]);
    config(['database.connections.pgsql_worker_b' => config('database.connections.pgsql')]);

    $a = DB::connection('pgsql_worker_a');
    $b = DB::connection('pgsql_worker_b');
    $allocator = app(TenantSequence::class);

    try {
        foreach ([$a, $b] as $connection) {
            $connection->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
        }
        $b->statement("SET lock_timeout = '1s'");

        // Worker A allocates and HOLDS its transaction open — the state a worker is in
        // while it writes the ticket that will carry the number.
        $a->beginTransaction();
        $first = $allocator->next('ticket_number', $a);
        expect($first)->toBe(1);

        // Worker B, concurrently, tries to allocate. If the allocator read-then-wrote, B
        // would compute 1 from the stale row and two customers would share a ticket
        // number. Instead B must BLOCK on A's row lock — proven by hitting lock_timeout,
        // which is only reachable by waiting on a lock.
        $b->beginTransaction();

        $blocked = null;
        try {
            $allocator->next('ticket_number', $b);
        } catch (Illuminate\Database\QueryException $e) {
            $blocked = $e;
        } finally {
            if ($b->transactionLevel() > 0) {
                $b->rollBack();
            }
        }

        expect($blocked)->not->toBeNull(
            'worker B allocated from a row worker A holds locked: the allocator is not '
            .'serialising on the UPDATE row lock, and duplicate ticket numbers are possible',
        );
        expect($blocked->getMessage())->toContain('lock timeout');

        // A resolves; B (in a fresh transaction) now allocates the NEXT number.
        $a->commit();

        $b->statement("SET lock_timeout = '5s'");
        $b->beginTransaction();
        $second = $allocator->next('ticket_number', $b);
        $b->commit();

        expect($second)->toBe(2);
    } finally {
        foreach (['pgsql_worker_a', 'pgsql_worker_b'] as $name) {
            $connection = DB::connection($name);
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            DB::purge($name);
        }

        cleanupTenants([$tenantId]);
    }
});
