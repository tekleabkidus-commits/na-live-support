<?php

declare(strict_types=1);

use App\Support\Events\EventPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EC-10 — the MONITOR half, the file the events migration promised by name.
 * Two red conditions, each proven red and green: fewer than MONTHS_AHEAD future
 * months covered, and ANY row in the DEFAULT partition. DDL cleanup in finally —
 * the migrator connection is auto-commit and the harness transaction cannot help.
 */
it('monitor passes on a healthy window (EC-10)', function () {
    expect(Artisan::call('partitions:check'))->toBe(0)
        ->and(Artisan::output())->toContain('PASS');
});

it('monitor goes RED when a future partition is missing, and maintain heals it (EC-10)', function () {
    $partitions = app(EventPartitions::class);
    $today = CarbonImmutable::now('UTC');
    $furthest = $partitions->nameFor($today->startOfMonth()->addMonths(EventPartitions::MONTHS_AHEAD));

    try {
        DB::connection('pgsql_migrator')->statement(sprintf('ALTER TABLE events DETACH PARTITION %s', $furthest));
        DB::connection('pgsql_migrator')->statement(sprintf('DROP TABLE %s', $furthest));

        expect(Artisan::call('partitions:check'))->toBe(1)
            ->and(Artisan::output())->toContain('future month(s) covered, need');
    } finally {
        // Heal THROUGH the maintenance command — proving the pair works as a pair.
        Artisan::call('partitions:maintain');
    }

    expect(Artisan::call('partitions:check'))->toBe(0);
});

it('monitor goes RED when any row sits in the DEFAULT partition (EC-10)', function () {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();
    commitFixtures();

    // The platform connection, not the migrator: FORCE RLS binds the owner too, so
    // the migrator cannot freely write rows — platform_admin (BYPASSRLS) is the only
    // role that can plant a stray the way no application path ever would.
    $platform = DB::connection('pgsql_platform');
    $rowId = (string) Str::uuid7();

    try {
        $platform->table('events')->insert([
            'id' => $rowId,
            'tenant_id' => $tenantId,
            'name' => 'fixture.stray_event',
            'payload' => '{}',
            'occurred_at' => CarbonImmutable::now('UTC')->addYears(2),
            'correlation_id' => (string) Str::uuid7(),
        ]);

        // The monitor's DEFAULT count runs on PlatformConnection, which refuses to
        // start while a tenant is bound (SEC-02) — clear the fixture binding the way
        // the real scheduler context is: unbound.
        expect(asPlatformWorker(fn (): int => Artisan::call('partitions:check')))->toBe(1)
            ->and(Artisan::output())->toContain('events_default');
    } finally {
        $platform->table('events')->where('id', $rowId)->delete();
        cleanupTenants([$tenantId]);
    }

    expect(asPlatformWorker(fn (): int => Artisan::call('partitions:check')))->toBe(0);
});
