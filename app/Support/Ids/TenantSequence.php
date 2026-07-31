<?php

declare(strict_types=1);

namespace App\Support\Ids;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Per-tenant human-facing counters (Doc 01 T-02, C-01).
 *
 * C-01: "human-facing numbers (ticket numbers) come from per-tenant counters in
 * `tenant_sequences` via UPDATE … RETURNING inside the owning transaction."
 *
 * Every clause of that sentence is a mechanism, not a preference:
 *
 *   UPDATE … RETURNING        one statement, so there is no read-then-write window for two
 *                             workers to read the same value. The row lock the UPDATE takes
 *                             is the serialisation: a concurrent allocator BLOCKS until the
 *                             first transaction resolves, it never computes from a stale row.
 *
 *   inside the owning         the caller's transaction, the one creating the ticket. If that
 *   transaction               transaction rolls back, the increment rolls back with it — which
 *                             is what makes the sequence gap-free without any compensation
 *                             logic. This class refuses to allocate outside a transaction,
 *                             because a number allocated autonomously is a number leaked the
 *                             moment its ticket fails to commit.
 *
 * UUIDv7 stays the identity everywhere (C-01); these numbers exist only for humans to read
 * aloud to each other. Nothing may join on them, and they are unique per (tenant, name), not
 * globally.
 *
 * WHY THE CONNECTION IS A PARAMETER. "The owning transaction" is a property of a specific
 * connection. Ordinary callers pass nothing and get the default; the concurrency proof in
 * tests/Isolation/SequenceAllocatorTest.php drives two real connections with overlapping
 * transactions — the same harness AC-05 uses — which is impossible against a facade-bound
 * singleton connection.
 */
final class TenantSequence
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Allocate the next number in a named per-tenant sequence, e.g. "ticket_number".
     */
    public function next(string $name, ?ConnectionInterface $connection = null): int
    {
        if ($name === '' || strlen($name) > 64) {
            throw new InvalidArgumentException('Sequence names are non-empty and at most 64 characters.');
        }

        $connection ??= DB::connection();

        if ($connection->transactionLevel() === 0) {
            throw new RuntimeException(
                'Sequence numbers are allocated inside the OWNING transaction (C-01) — the one '
                .'writing the row that will carry the number — so a rollback rolls the counter '
                .'back too and the sequence stays gap-free. Open the transaction first.',
            );
        }

        $tenantId = $this->context->tenantId();

        // First use of a name creates its counter row. ON CONFLICT DO NOTHING makes the
        // creation race safe: two first-ever allocators both INSERT, one wins, the loser
        // proceeds to the UPDATE and blocks on the winner's row lock like any other caller.
        $connection->statement(
            'INSERT INTO tenant_sequences (id, tenant_id, name, next_value, created_at, updated_at) '
            .'VALUES (?, ?, ?, 1, now(), now()) ON CONFLICT (tenant_id, name) DO NOTHING',
            [(string) Str::uuid7(), $tenantId, $name],
        );

        /** @var list<object{allocated: int|string}> $rows */
        $rows = $connection->select(
            'UPDATE tenant_sequences SET next_value = next_value + 1, updated_at = now() '
            .'WHERE tenant_id = ? AND name = ? RETURNING next_value - 1 AS allocated',
            [$tenantId, $name],
        );

        if ($rows === []) {
            // RLS filtered the row: the bound context and the requested tenant disagree.
            // Reachable only through a bug in the binding discipline, so say so loudly.
            throw new RuntimeException(sprintf(
                'Sequence "%s" is invisible under the current tenant binding — the RLS layer '
                .'refused the counter row. The binding, not this allocator, is the bug.',
                $name,
            ));
        }

        return (int) $rows[0]->allocated;
    }
}
