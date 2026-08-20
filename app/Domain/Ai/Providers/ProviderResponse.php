<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

/** One provider call's typed result (T-01). */
final readonly class ProviderResponse
{
    /**
     * @param  list<list<float>>  $vectors  embeddings calls only
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $content,
        public int $tokensIn,
        public int $tokensOut,
        public array $raw = [],
        public array $vectors = [],
    ) {}
}
