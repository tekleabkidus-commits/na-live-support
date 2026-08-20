<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

/**
 * T-01's provider driver interface. `stream()` joins at step 9 (T-04) —
 * declared now so no driver ships without deciding its streaming posture.
 *
 * @phpstan-type Payload array<string, mixed>
 */
interface ProviderDriver
{
    /** @param array<string, mixed> $payload the OpenAI-compatible request body */
    public function complete(array $payload): ProviderResponse;

    /** @param array<string, mixed> $payload */
    public function embeddings(array $payload): ProviderResponse;
}
