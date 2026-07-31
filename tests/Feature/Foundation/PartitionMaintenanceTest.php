<?php

declare(strict_types=1);

use App\Support\Events\EventPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EC-10 / NFR-07 — the MAINTENANCE half: naming agreement with the migration,
 * idempotent future-month creation, and the 13-month retention drop. The monitor
 * half lives in PartitionMonitorTest.php, the file the events migration promised.
 *
 * DDL on the migrator connection is auto-commit — the harness transaction does not
 * protect it — so every test that mutates partitions restores them in a finally block.
 * "Today" is always passed in; expected names are computed with the same calendar
 * arithmetic production uses, never written as literals.
 */
function migratorStatement(string $sql): void
{
    DB::connection('pgsql_migrator')->statement($sql);
}

it('agrees with the migration about partition names, for the months the migration created', function () {
    $partitions = app(EventPartitions::class);
    $today = CarbonImmutable::now('UTC');

    // The migration created current-1 … current+3. If the naming conventions ever
    // diverge, nameFor() produces a name existing() cannot find, and this fails.
    foreach ([-1, 0, 1, 2, 3] as $offset) {
        $name = $partitions->nameFor($today->startOfMonth()->addMonths($offset));
        expect($partitions->existing())->toContain($name);
    }
});

it('creates only the missing future months, idempotently (EC-10)', function () {
    $partitions = app(EventPartitions::class);
    $today = CarbonImmutable::now('UTC');

    // Everything already exists for the real window: a run creates nothing.
    expect($partitions->ensureFutureMonths($today))->toBe([]);

    // Advance the clock one month: exactly ONE partition is missing (current+3 from
    // the shifted perspective), and a second run from the same date creates nothing.
    $shifted = $today->startOfMonth()->addMonth();
    $expected = $partitions->nameFor($shifted->addMonths(EventPartitions::MONTHS_AHEAD));

    try {
        expect($partitions->ensureFutureMonths($shifted))->toBe([$expected])
            ->and($partitions->ensureFutureMonths($shifted))->toBe([]);
    } finally {
        migratorStatement(sprintf('DROP TABLE IF EXISTS %s', $expected));
    }
});

it('drops partitions beyond the 13-month retention, with a logged metric (NFR-07)', function () {
    Log::spy();

    $partitions = app(EventPartitions::class);
    $today = CarbonImmutable::now('UTC');

    // A synthetic partition 14 months old (outside retention) and one exactly at the
    // 13-month boundary (inside — the cutoff is STRICTLY older than).
    $expiredMonth = $today->startOfMonth()->subMonths(14);
    $boundaryMonth = $today->startOfMonth()->subMonths(13);
    $expiredName = $partitions->nameFor($expiredMonth);
    $boundaryName = $partitions->nameFor($boundaryMonth);

    try {
        foreach ([[$expiredName, $expiredMonth], [$boundaryName, $boundaryMonth]] as [$name, $month]) {
            migratorStatement(sprintf(
                "CREATE TABLE %s PARTITION OF events FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $month->toDateTimeString(),
                $month->addMonth()->toDateTimeString(),
            ));
        }

        expect($partitions->dropExpired($today))->toBe([$expiredName])
            ->and($partitions->existing())->not->toContain($expiredName)
            // The boundary month SURVIVES: 13 rolling months means 13 kept, not 12.
            ->and($partitions->existing())->toContain($boundaryName);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => $message === 'events partition dropped per NFR-07 retention'
                && ($context['partition'] ?? null) === $expiredName
                && ($context['metric'] ?? null) === 'events.partition_dropped');
    } finally {
        migratorStatement(sprintf('DROP TABLE IF EXISTS %s', $expiredName));
        migratorStatement(sprintf('DROP TABLE IF EXISTS %s', $boundaryName));
    }
});

it('runs partitions:maintain end-to-end, idempotently', function () {
    expect(Artisan::call('partitions:maintain'))->toBe(0);
    $first = Artisan::output();

    expect(Artisan::call('partitions:maintain'))->toBe(0);

    // Second run converges: nothing created, nothing dropped.
    expect(Artisan::output())->toContain('0 created')->toContain('0 dropped');
    expect($first)->toContain('future month(s) covered');
});
