<?php

declare(strict_types=1);

namespace App\Domain\Ai\Supervisor;

use App\Domain\Ai\DecisionLog\DecisionRecord;
use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Ai\Prompt\ImmutableLayer;
use Symfony\Component\Uid\UuidV7;

/**
 * FR-17's outputs are DECISION ROWS (surface=supervisor) — the data products
 * Doc 15 renders [D15] and FR-20's weekly outputs read; no second store
 * (the FR-16 posture). One pen, typed; provider 'none' rows are the
 * arithmetic ones (anomaly deltas, sentiment watch) — the model is called
 * only where language is generated (qa_prescore, daily_digest).
 */
final class SupervisorLog
{
    public function __construct(private readonly DecisionWriter $decisions) {}

    /**
     * @param  array<string, mixed>  $payload  aggregates, identifiers, categories — never message text
     * @param  array<string, string>  $layerHashes
     * @param  array<string, mixed>|null  $request
     */
    public function write(
        string $task,
        string $action,
        array $payload,
        ?string $conversationId = null,
        ?string $ticketId = null,
        ?string $language = null,
        ?float $confidence = null,
        string $provider = 'none',
        string $model = 'none',
        array $layerHashes = [],
        int $tokensIn = 0,
        int $tokensOut = 0,
        int $latencyMs = 0,
        ?array $request = null,
        ?string $errorCategory = null,
    ): string {
        $id = (string) new UuidV7;
        $this->decisions->record(new DecisionRecord(
            id: $id, surface: 'supervisor', task: $task, provider: $provider, model: $model,
            layerHashes: $layerHashes === [] ? ['immutable' => ImmutableLayer::hash()] : $layerHashes,
            conversationId: $conversationId, ticketId: $ticketId, language: $language,
            confidence: $confidence, action: $action, tokensIn: $tokensIn, tokensOut: $tokensOut,
            latencyMs: $latencyMs, errorCategory: $errorCategory, requestPayload: $request, responsePayload: $payload,
        ));
        $this->decisions->flush();

        return $id;
    }
}
