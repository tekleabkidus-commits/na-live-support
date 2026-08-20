<?php

declare(strict_types=1);

namespace App\Domain\Ai\DecisionLog;

/**
 * One task invocation's decision row (FR-20), typed at the seam so a caller
 * cannot stage a shapeless row. Payloads arrive ALREADY masked (SEC-02 runs
 * at assembly, before egress — what the log stores is what the provider
 * saw).
 */
final readonly class DecisionRecord
{
    /**
     * @param  array<string, string>  $layerHashes
     * @param  array<string, mixed>|null  $requestPayload
     * @param  array<string, mixed>|null  $responsePayload
     */
    public function __construct(
        public string $id,
        public string $surface,
        public string $task,
        public string $provider,
        public string $model,
        public array $layerHashes,
        public ?string $configVersionId = null,
        public ?string $conversationId = null,
        public ?string $ticketId = null,
        public ?string $customerId = null,
        public ?string $messageId = null,
        public ?string $endpointRef = null,
        public ?string $language = null,
        public ?string $intent = null,
        public ?float $confidence = null,
        public ?string $action = null,
        public ?float $rgRisk = null,
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public int $latencyMs = 0,
        public ?string $errorCategory = null,
        public ?array $requestPayload = null,
        public ?array $responsePayload = null,
    ) {}
}
