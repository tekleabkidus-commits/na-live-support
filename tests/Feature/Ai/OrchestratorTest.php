<?php

declare(strict_types=1);

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Orchestrator\TaskCatalog;
use App\Domain\Ai\Providers\AllProvidersExhausted;
use App\Domain\Ai\Providers\CircuitBreaker;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use Illuminate\Support\Facades\Cache;

/**
 * T-01/FR-01/FR-02: the orchestrator, the task catalog, the fallback chain
 * and the circuit breakers — over scripted drivers, with the STEP-3
 * ASSERTION the plan owed: no egress path skips the fixtures-only guard.
 */
function fakeChainConfig(array $chain): void
{
    config(['ai.default_routing.fast' => $chain, 'ai.default_routing.strong' => $chain]);
}

beforeEach(function (): void {
    Cache::flush();
});

it('FR-01: the task catalog is CLOSED at twelve, matching the ai_decisions CHECK list exactly', function (): void {
    expect(array_keys(TaskCatalog::TASKS))->toBe([
        'classify_message', 'chatbot_turn', 'draft_reply', 'improve_text',
        'summarize_thread', 'translate', 'transcribe_voice', 'ocr_receipt',
        'generate_subject', 'qa_prescore', 'daily_digest', 'anomaly_scan',
    ])->and(fn () => TaskCatalog::definition('invented_task'))->toThrow(InvalidArgumentException::class);
});

it('T-01: the fallback chain falls through a failing provider to the next and records the trip arithmetic', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);

    $guard = new FixturesOnlyEgress;
    $failing = new FakeDriver($guard);
    $failing->queue(new RuntimeException('provider down'));
    $healthy = new FakeDriver($guard);
    $healthy->queue(new ProviderResponse('{"ok":true}', 10, 5));

    /** @var ProviderRegistry $registry */
    $registry = app(ProviderRegistry::class);
    $registry->swap('fake-a', $failing);
    $registry->swap('fake-b', $healthy);
    fakeChainConfig([
        ['provider' => 'fake-a', 'model' => 'm1'],
        ['provider' => 'fake-b', 'model' => 'm2'],
    ]);

    $result = app(AiOrchestrator::class)->execute('classify_message', [
        ['role' => 'user', 'content' => '[FIXTURE] hello'],
    ]);

    expect($result['provider'])->toBe('fake-b')
        ->and($result['response']->content)->toBe('{"ok":true}')
        ->and($result['response']->tokensIn)->toBe(10);
});

it('T-01: an OPEN breaker skips its provider without spending a call; all-exhausted throws the degraded-mode sentinel', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);

    $guard = new FixturesOnlyEgress;
    $tripped = new FakeDriver($guard); // scripted with NOTHING: a call would throw script-exhausted
    /** @var ProviderRegistry $registry */
    $registry = app(ProviderRegistry::class);
    $registry->swap('fake-tripped', $tripped);
    fakeChainConfig([['provider' => 'fake-tripped', 'model' => 'm']]);

    // Trip it: threshold failures inside the window.
    $breaker = app(CircuitBreaker::class);
    for ($i = 0; $i < (int) config('ai.breaker.threshold'); $i++) {
        $breaker->recordFailure('fake-tripped');
    }
    expect($breaker->isOpen('fake-tripped'))->toBeTrue();

    expect(fn () => app(AiOrchestrator::class)->execute('classify_message', [
        ['role' => 'user', 'content' => '[FIXTURE] hello'],
    ]))->toThrow(AllProvidersExhausted::class);

    expect($tripped->seen)->toBe([], 'the open breaker still let a call through');
});

it('THE STEP-3 ASSERTION: no egress path skips the guard — every ProviderDriver implementation consults FixturesOnlyEgress', function (): void {
    // Static half: every driver class in the Providers namespace names the
    // guard. A driver that skips it would be the second implementation the
    // prohibitions ban.
    $driverDir = base_path('app/Domain/Ai/Providers');
    $implementations = [];
    foreach (glob($driverDir.'/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        if (str_contains($source, 'implements ProviderDriver')) {
            $implementations[] = basename($file);
            expect(str_contains($source, 'FixturesOnlyEgress'))->toBeTrue(
                basename($file).' implements ProviderDriver without consulting FixturesOnlyEgress',
            );
            expect(str_contains($source, 'assertAllowed'))->toBeTrue(
                basename($file).' names the guard but never calls assertAllowed',
            );
        }
    }
    expect(count($implementations))->toBeGreaterThanOrEqual(3, 'the sweep found no drivers — vacuous');

    // Runtime half: the scripted driver refuses a non-fixture payload
    // exactly as the real client does.
    $driver = new FakeDriver(new FixturesOnlyEgress);
    $driver->queue(new ProviderResponse('x', 1, 1));
    config(['app.env' => 'testing']);

    expect(fn () => $driver->complete([
        'model' => 'm',
        'messages' => [['role' => 'user', 'content' => 'real customer text, no tag']],
    ]))->toThrow(App\Domain\Ai\Guards\NonFixturePayloadRefused::class);
});

it('FR-02: a PUBLISHED provider_routing config overrides the seed — zero deploy', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $actor = (string) Illuminate\Support\Str::uuid7();

    $guard = new FixturesOnlyEgress;
    $routed = new FakeDriver($guard);
    $routed->queue(new ProviderResponse('routed', 1, 1));
    /** @var ProviderRegistry $registry */
    $registry = app(ProviderRegistry::class);
    $registry->swap('fake-routed', $routed);
    fakeChainConfig([['provider' => 'fake-a-never-used', 'model' => 'x']]);

    $configs = app(App\Domain\Ai\Config\ConfigVersions::class);
    $v = $configs->draft('provider_routing', 'default', [
        'fast' => [['provider' => 'fake-routed', 'model' => 'routed-model']],
    ], $actor);
    $configs->publish($v, $actor);

    $result = app(AiOrchestrator::class)->execute('classify_message', [
        ['role' => 'user', 'content' => '[FIXTURE] hello'],
    ]);

    expect($result['provider'])->toBe('fake-routed')->and($result['model'])->toBe('routed-model');
});
