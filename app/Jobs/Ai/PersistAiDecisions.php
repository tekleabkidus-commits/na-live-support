<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Domain\Ai\DecisionLog\DecisionRecord;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * The decision log's batched insert (NFR-05's async half) — ai-background
 * queue (ADR 0017): a log backlog must never delay a customer turn (FS-04's
 * own posture: completion unaffected, reports lag, reconcile on drain).
 *
 * created_at is the ROW'S DB-time birth (clock_timestamp default, R-19a
 * app-denied) — DELIBERATELY: the task finished moments earlier, but an
 * app-supplied "task time" stamp is exactly the wall-clock class R-19
 * banned. Queue latency shifts the log stamp by seconds at worst; latency_ms
 * carries the task's own duration.
 *
 * Payload columns are inserted here and NEVER updated by app (grant
 * physics); the SEC-06 reduction job (platform role) is their only mutator.
 */
final class PersistAiDecisions implements ShouldQueue
{
    use Queueable;
    use RunsWithinTenant;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly string $tenantId,
        public readonly array $rows,
    ) {
        $this->onQueue('ai-background');
    }

    /**
     * The one row shape (kept beside the writer so the pair cannot drift).
     *
     * @return array<string, mixed>
     */
    public static function rowFor(DecisionRecord $record): array
    {
        return [
            'id' => $record->id,
            'surface' => $record->surface,
            'task' => $record->task,
            'provider' => $record->provider,
            'model' => $record->model,
            'config_version_id' => $record->configVersionId,
            'layer_hashes' => json_encode($record->layerHashes, JSON_THROW_ON_ERROR),
            'conversation_id' => $record->conversationId,
            'ticket_id' => $record->ticketId,
            'customer_id' => $record->customerId,
            'message_id' => $record->messageId,
            'endpoint_ref' => $record->endpointRef,
            'language' => $record->language,
            'intent' => $record->intent,
            'confidence' => $record->confidence,
            'action' => $record->action,
            'rg_risk' => $record->rgRisk,
            'tokens_in' => $record->tokensIn,
            'tokens_out' => $record->tokensOut,
            'latency_ms' => $record->latencyMs,
            'error_category' => $record->errorCategory,
            'request_payload' => $record->requestPayload === null ? null : json_encode($record->requestPayload, JSON_THROW_ON_ERROR),
            'response_payload' => $record->responsePayload === null ? null : json_encode($record->responsePayload, JSON_THROW_ON_ERROR),
        ];
    }

    public function handle(): void
    {
        $this->withinTenant($this->tenantId, function (): void {
            foreach (array_chunk($this->rows, 100) as $chunk) {
                $withTenant = array_map(fn (array $row): array => $row + ['tenant_id' => $this->tenantId], $chunk);
                DB::table('ai_decisions')->insert($withTenant);
            }
        });
    }
}
