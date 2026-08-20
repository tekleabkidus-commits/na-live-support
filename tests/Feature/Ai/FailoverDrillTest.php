<?php

declare(strict_types=1);

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Providers\AllProvidersExhausted;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderHealth;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Providers\RefusalDetector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * FS-01/NFR-04/T-01 — THE LADDER'S PROVIDER SIDE: breaker trips → degraded
 * event; chain exhausted → down event; recovery → healthy; all TRANSITION-
 * ONLY (the rule-16 amendment's A-22 shape). EC-11's refusal detection.
 * The customer-side clauses (bypass-to-handoff within one turn, the degraded
 * Copilot card, Supervisor pause — AC-09's zero-unanswered drill) complete
 * at step 9 in this same suite when the turn executor exists.
 */
beforeEach(function (): void {
    Cache::flush();
});

function healthEvents(): array
{
    return DB::table('outbox')->where('name', 'ai.provider.health_changed')->orderBy('occurred_at')->get()
        ->map(fn (object $row): array => json_decode((string) $row->payload, true))->all();
}

it('FS-01: primary fails → breaker trips → DEGRADED (transition-only); the chain falls through to the secondary', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);

    $guard = new FixturesOnlyEgress;
    $primary = new FakeDriver($guard); // empty script: every call throws
    $secondary = new FakeDriver($guard);
    for ($i = 0; $i < 8; $i++) {
        $secondary->queue(new ProviderResponse('{"ok":true}', 10, 5));
    }
    app(ProviderRegistry::class)->swap('fake-primary', $primary);
    app(ProviderRegistry::class)->swap('fake-secondary', $secondary);
    config(['ai.default_routing.fast' => [['provider' => 'fake-primary', 'model' => 'p'], ['provider' => 'fake-secondary', 'model' => 's']]]);
    config(['ai.breaker.threshold' => 3]);

    for ($i = 0; $i < 4; $i++) {
        $result = app(AiOrchestrator::class)->execute('classify_message', [['role' => 'user', 'content' => '[FIXTURE] q'.$i]]);
        expect($result['provider'])->toBe('fake-secondary');
    }

    $events = healthEvents();
    $primaryEvents = array_values(array_filter($events, fn (array $e): bool => $e['provider'] === 'fake-primary'));
    expect(array_column($primaryEvents, 'status'))->toBe(['degraded'], 'expected exactly ONE degraded transition for the primary (transition-only)')
        ->and(array_column(array_values(array_filter($events, fn (array $e): bool => $e['provider'] === 'fake-secondary')), 'status'))->toBe(['healthy']);
});

it('FS-01/NFR-04: every provider exhausted → DOWN for this tenant, the orchestrator throws the degraded-mode sentinel within the turn', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);

    app(ProviderRegistry::class)->swap('fake-dead', new FakeDriver(new FixturesOnlyEgress));
    config(['ai.default_routing.fast' => [['provider' => 'fake-dead', 'model' => 'd']]]);

    expect(fn () => app(AiOrchestrator::class)->execute('classify_message', [['role' => 'user', 'content' => '[FIXTURE] z']]))
        ->toThrow(AllProvidersExhausted::class);

    $statuses = array_column(array_filter(healthEvents(), fn (array $e): bool => $e['provider'] === 'fake-dead'), 'status');
    expect($statuses)->toBe(['down']);
});

it('FS-01: auto-resume — a success after DOWN publishes HEALTHY once; repeats publish nothing', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $health = app(ProviderHealth::class);

    $health->observe('fake-x', 'down');
    $health->observe('fake-x', 'healthy');
    $health->observe('fake-x', 'healthy');
    $health->observe('fake-x', 'healthy');

    expect(array_column(array_filter(healthEvents(), fn (array $e): bool => $e['provider'] === 'fake-x'), 'status'))->toBe(['down', 'healthy'])
        ->and($health->current('fake-x'))->toBe('healthy');
});

it('EC-11: a policy refusal on benign content is detected as provider_refusal — by finish_reason or by refusal-shaped prose; honored JSON is never a refusal', function (): void {
    $detector = new RefusalDetector;

    expect($detector->isPolicyRefusal(new ProviderResponse("I'm sorry, but I can't help with that request.", 1, 1)))->toBeTrue()
        ->and($detector->isPolicyRefusal(new ProviderResponse('This request violates our usage policies.', 1, 1)))->toBeTrue()
        ->and($detector->isPolicyRefusal(new ProviderResponse('x', 1, 1, ['choices' => [['finish_reason' => 'content_filter']]])))->toBeTrue()
        ->and($detector->isPolicyRefusal(new ProviderResponse('{"action":"handoff","reason":"no_grounding"}', 1, 1)))->toBeFalse()
        ->and($detector->isPolicyRefusal(new ProviderResponse('Deposits with telebirr settle within 30 minutes.', 1, 1)))->toBeFalse();
});
