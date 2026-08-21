<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use RuntimeException;

/**
 * The scripted unit-test driver. DELIBERATELY consults the egress guard
 * exactly as the real client does — a test double that skips the guard
 * would prove pipelines safe that are not (the no-second-implementation
 * law applied to safety checks).
 */
final class FakeDriver implements ProviderDriver
{
    /** @var list<ProviderResponse|\Throwable> */
    private array $script = [];

    /** @var list<array<string, mixed>> every payload this driver saw */
    public array $seen = [];

    public function __construct(private readonly FixturesOnlyEgress $guard) {}

    public function queue(ProviderResponse|\Throwable $response): void
    {
        $this->script[] = $response;
    }

    public function complete(array $payload): ProviderResponse
    {
        return $this->next($payload);
    }

    public function embeddings(array $payload): ProviderResponse
    {
        return $this->next($payload);
    }

    /**
     * T-04 posture: the scripted content is delivered in sentence-boundary
     * chunks so the relay's buffering is exercised by the same script.
     */
    public function stream(array $payload, callable $onDelta): ProviderResponse
    {
        $response = $this->next($payload);
        foreach (SentenceChunker::chunks((string) $response->content) as $chunk) {
            $onDelta($chunk);
        }

        return $response;
    }

    /** @param array<string, mixed> $payload */
    private function next(array $payload): ProviderResponse
    {
        $this->guard->assertAllowed($payload);
        $this->seen[] = $payload;

        $next = array_shift($this->script);
        if ($next === null) {
            throw new RuntimeException('FakeDriver script exhausted');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
