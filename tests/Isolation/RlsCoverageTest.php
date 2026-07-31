<?php

declare(strict_types=1);

use App\Support\Events\EventPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The test the RLS migration promised by name: NO events partition is ever bare.
 *
 * A partitioned parent's policies govern rows reached THROUGH the parent; a direct
 * query against a partition is governed only by the partition's own policies. So a
 * partition created without enable+force+policy is a month-sized hole in NFR-01 —
 * invisible to every parent-path test, which is why this file checks the catalog
 * per partition rather than trusting any code path.
 */
function partitionRlsFacts(): array
{
    /** @var list<object{relname: string, relrowsecurity: bool, relforcerowsecurity: bool, policies: int|string}> $rows */
    return DB::connection('pgsql_migrator')->select("
        SELECT c2.relname,
               c2.relrowsecurity,
               c2.relforcerowsecurity,
               (SELECT count(*) FROM pg_policy p WHERE p.polrelid = c2.oid) AS policies
        FROM pg_inherits i
        JOIN pg_class c ON c.oid = i.inhparent
        JOIN pg_class c2 ON c2.oid = i.inhrelid
        WHERE c.relname = 'events'
        ORDER BY c2.relname
    ");
}

it('finds every existing events partition enabled, forced, and policed', function () {
    $facts = partitionRlsFacts();

    expect($facts)->not->toBeEmpty();

    foreach ($facts as $fact) {
        expect($fact->relrowsecurity)->toBeTrue("{$fact->relname} has RLS disabled")
            ->and($fact->relforcerowsecurity)->toBeTrue("{$fact->relname} does not FORCE RLS — the owner bypasses it")
            ->and((int) $fact->policies)->toBeGreaterThan(0, "{$fact->relname} has no policy — bare partition");
    }
});

it('protects partitions the MAINTENANCE COMMAND creates, not only the migration ones', function () {
    $partitions = app(EventPartitions::class);
    $today = CarbonImmutable::now('UTC');

    // Force creation of a genuinely new partition (one month past the real window).
    $shifted = $today->startOfMonth()->addMonth();
    $name = $partitions->nameFor($shifted->addMonths(EventPartitions::MONTHS_AHEAD));

    try {
        expect($partitions->ensureFutureMonths($shifted))->toBe([$name]);

        $fact = collect(partitionRlsFacts())->firstWhere('relname', $name);

        expect($fact)->not->toBeNull()
            ->and($fact->relrowsecurity)->toBeTrue()
            ->and($fact->relforcerowsecurity)->toBeTrue()
            ->and((int) $fact->policies)->toBeGreaterThan(0);
    } finally {
        DB::connection('pgsql_migrator')->statement(sprintf('DROP TABLE IF EXISTS %s', $name));
    }
});
