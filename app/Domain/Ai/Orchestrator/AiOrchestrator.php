<?php

declare(strict_types=1);

namespace App\Domain\Ai\Orchestrator;

use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\Providers\AllProvidersExhausted;
use App\Domain\Ai\Providers\CircuitBreaker;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use Throwable;

/**
 * T-01: the orchestrator — typed task execution over the provider seam with
 * the fallback-chain executor and per-provider circuit breakers.
 *
 * Routing: the tenant's PUBLISHED provider_routing config (FR-02, zero
 * deploy) or config/ai.php's default seed. Every provider in the chain is
 * tried in order; an open breaker skips; a failure records and falls
 * through; all exhausted throws AllProvidersExhausted (degraded mode — the
 * step-8 ladder's trigger). Decision logging joins at step 4 (the writer
 * wraps execute()); queueing is the callers' (FR-03: every task call is a
 * queued job on the ai family — the jobs land with their steps).
 */
final class AiOrchestrator
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly CircuitBreaker $breaker,
        private readonly ConfigVersions $configs,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{response: ProviderResponse, provider: string, model: string, latency_ms: int}
     */
    public function execute(string $task, array $messages): array
    {
        $definition = TaskCatalog::definition($task);
        $chain = $this->routingFor($definition['tier']);
        $lastFailure = null;

        foreach ($chain as $hop) {
            $provider = $hop['provider'];
            $model = $hop['model'];

            if ($this->breaker->isOpen($provider)) {
                continue;
            }

            $started = microtime(true);

            try {
                $response = $this->providers->driver($provider, $definition['timeout_s'])->complete([
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => $definition['max_tokens'],
                    'temperature' => $definition['tier'] === 'fast' ? 0 : 0.3,
                ]);

                $this->breaker->recordSuccess($provider);

                return [
                    'response' => $response,
                    'provider' => $provider,
                    'model' => $model,
                    'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                ];
            } catch (Throwable $failure) {
                $this->breaker->recordFailure($provider);
                $lastFailure = $failure;
            }
        }

        throw new AllProvidersExhausted(
            'every provider in the chain failed or sits tripped (T-01 degraded mode)',
            previous: $lastFailure,
        );
    }

    /** @return list<array{provider: string, model: string}> */
    private function routingFor(string $tier): array
    {
        $published = $this->configs->published('provider_routing', 'default');

        $routing = null;
        if ($published !== null) {
            $decoded = json_decode($published->payload, true);
            if (is_array($decoded) && is_array($decoded[$tier] ?? null)) {
                $routing = $decoded[$tier];
            }
        }

        if ($routing === null) {
            $seed = config("ai.default_routing.{$tier}", []);
            $routing = is_array($seed) ? $seed : [];
        }

        $chain = [];
        foreach ($routing as $hop) {
            if (is_array($hop) && is_string($hop['provider'] ?? null) && is_string($hop['model'] ?? null)) {
                $chain[] = ['provider' => $hop['provider'], 'model' => $hop['model']];
            }
        }

        return $chain;
    }
}
