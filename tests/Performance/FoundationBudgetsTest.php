<?php

declare(strict_types=1);

use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AC-07, PARTIAL BY DESIGN — the §12 latency budgets, measured and asserted.
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * EVERY NUMBER THIS FILE PRODUCES IS DEV-HARDWARE-INDICATIVE (owner ruling,
 * S2 of Doc 01). AC-07 proper reads: "p99 latency budget (§12) verified under load
 * profile L1 (defined in Doc 19) with dashboards as evidence." No L1 profile exists
 * yet, there is no concurrent load here, and a laptop container is not the production
 * topology. What this file DOES prove: the code paths meet their budgets with zero
 * contention on the hardware that built them — a necessary condition, never the
 * sufficient one. registries/traceability.yaml carries AC-07 as `partial` for
 * exactly this reason; Doc 19 L1 owns the upgrade to proven.
 *
 * §12 rows measured here: resolution ≤1 ms p99, settings read ≤0.5 ms p99, events
 * insert ≤1 ms p99, registry cold-load ≤50 ms, plus NFR-04's outbox publish ≤5 ms p99.
 * §12 rows NOT measurable yet: "foundation adds ≤3 ms to a simple authenticated GET"
 * (needs Doc 02's authentication to exist) and relay lag under 10× burst (needs L1).
 *
 * TWO ASSERTION MODES, because single-run p99 on shared hardware is noisy — during this
 * suite's own development, identical code went green, red, green across three runs on an
 * idle machine. A spec-tight assertion in the default run would fail CI on noise, and a
 * flaky red main teaches everyone to ignore red.
 *
 *   default                      asserts 5× the budget: catches an order-of-magnitude
 *                                regression on any run, tolerates a stray checkpoint.
 *   PERF_BUDGETS_ENFORCE=1       asserts the spec budgets exactly (`make perf`); this is
 *                                the mode whose numbers are quoted, and the mode Doc 19's
 *                                L1 stage runs in a controlled environment.
 * ────────────────────────────────────────────────────────────────────────────────
 */
const PERF_SAMPLES = 2000;

/**
 * @param  callable(): void  $operation
 * @return array{p50: float, p99: float, max: float} milliseconds
 */
function measure(callable $operation, int $samples = PERF_SAMPLES): array
{
    foreach (range(1, 50) as $i) {
        $operation(); // warm-up: connections, statement cache, opcache
    }

    $timings = [];
    foreach (range(1, $samples) as $i) {
        $start = hrtime(true);
        $operation();
        $timings[] = (hrtime(true) - $start) / 1_000_000;
    }

    sort($timings);

    return [
        'p50' => $timings[(int) ($samples * 0.50)],
        'p99' => $timings[(int) ($samples * 0.99)],
        'max' => $timings[$samples - 1],
    ];
}

function reportBudget(string $name, float $budgetMs, array $stats): void
{
    fwrite(STDERR, sprintf(
        "  [dev-hardware-indicative] %-28s p50 %7.3f ms   p99 %7.3f ms   max %7.3f ms   budget(p99) %.1f ms   %s\n",
        $name,
        $stats['p50'],
        $stats['p99'],
        $stats['max'],
        $budgetMs,
        $stats['p99'] <= $budgetMs ? 'WITHIN' : 'OVER',
    ));
}

/** The spec budget under PERF_BUDGETS_ENFORCE=1; a 5× regression ceiling otherwise. */
function assertedBudget(float $specBudgetMs): float
{
    return getenv('PERF_BUDGETS_ENFORCE') === '1' ? $specBudgetMs : $specBudgetMs * 5.0;
}

it('meets the §12 budgets on dev hardware: resolution ≤1ms, settings ≤0.5ms, events ≤1ms p99', function () {
    $tenant = activateTenant(createTenant());
    $tenantId = $tenant->tenantId();

    $resolver = app(TenantResolver::class);
    $settings = app(SettingsRepository::class);
    $settings->get('tenant.default_language'); // prime the Redis hash

    $resolution = measure(function () use ($resolver, $tenantId): void {
        $resolver->resolveTrustedTenantId($tenantId, ResolutionSource::Console);
    });
    reportBudget('tenant resolution (NFR-02)', 1.0, $resolution);

    $settingsRead = measure(function () use ($settings): void {
        $settings->get('tenant.default_language');
    });
    reportBudget('settings cached read (NFR-03)', 0.5, $settingsRead);

    $eventsInsert = measure(function () use ($tenantId): void {
        DB::table('events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'name' => 'perf.fixture_event',
            'payload' => '{}',
            'occurred_at' => now(),
            'correlation_id' => (string) Str::uuid7(),
        ]);
    });
    reportBudget('events table insert (§12)', 1.0, $eventsInsert);

    // A real cataloged event: the publisher refuses undocumented names (§21), and the
    // benchmark must measure the same validation the production path pays for.
    $outboxPublish = measure(function () use ($tenantId): void {
        app(EventPublisher::class)->publish(name: 'tenant.provisioned', payload: ['tenant_id' => $tenantId]);
    });
    reportBudget('outbox publish (NFR-04)', 5.0, $outboxPublish);

    expect($resolution['p99'])->toBeLessThanOrEqual(assertedBudget(1.0), 'NFR-02: resolution p99 over budget')
        ->and($settingsRead['p99'])->toBeLessThanOrEqual(assertedBudget(0.5), 'NFR-03: settings p99 over budget')
        ->and($eventsInsert['p99'])->toBeLessThanOrEqual(assertedBudget(1.0), '§12: events insert p99 over budget')
        ->and($outboxPublish['p99'])->toBeLessThanOrEqual(assertedBudget(5.0), 'NFR-04: outbox publish p99 over budget');
});

it('cold-loads the settings registry within 50 ms (§12)', function () {
    createTenant();

    $start = hrtime(true);

    /** @var array<int, array<string, mixed>> $registry */
    $registry = require base_path('registries/settings.php');

    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    fwrite(STDERR, sprintf(
        "  [dev-hardware-indicative] %-28s once %7.3f ms   budget 50.0 ms   %s\n",
        'registry cold-load (§12)',
        $elapsedMs,
        $elapsedMs <= 50.0 ? 'WITHIN' : 'OVER',
    ));

    expect($registry)->not->toBeEmpty()
        ->and($elapsedMs)->toBeLessThanOrEqual(50.0);
});
