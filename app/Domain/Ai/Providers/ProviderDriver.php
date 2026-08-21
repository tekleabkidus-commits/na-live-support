<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

/**
 * T-01's provider driver interface. `stream()` is T-04's provider side
 * (S09 step 10 — the Copilot card is the first consumer; Telegram takes
 * the COMPLETED message per T-04 and never streams). Every driver decides
 * its streaming posture explicitly: the real client speaks SSE, the
 * scripted/replay drivers chunk their fixture content at sentence
 * boundaries so the relay path is exercised by the same tests.
 *
 * @phpstan-type Payload array<string, mixed>
 */
interface ProviderDriver
{
    /** @param array<string, mixed> $payload the OpenAI-compatible request body */
    public function complete(array $payload): ProviderResponse;

    /**
     * T-04: deltas reach $onDelta IN ORDER as they arrive; the returned
     * response carries the FULL content + usage (T-07 meters the whole).
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(string): void  $onDelta
     */
    public function stream(array $payload, callable $onDelta): ProviderResponse;

    /** @param array<string, mixed> $payload */
    public function embeddings(array $payload): ProviderResponse;
}
