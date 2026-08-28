<?php

declare(strict_types=1);

namespace App\Jobs\Kb;

use App\Domain\Kb\Publish\PublishPipeline;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Doc 10 FR-13 / T-04 / T-05 / FS-01 — the DURABLE consumer of kb.article.published
 * (ADR 0011: registry + drill pin + the NFR-06 fixtures in the SAME commit). Rides the
 * ai-background family (ADR 0017: embeddings never delay a customer turn). IDEMPOTENT
 * ON event_id (C-09): it reads identifiers only, re-fetches truth, and the pipeline
 * treats an already-serving version as a no-op. Embeddings down → the pipeline throws
 * → tries ×3 → dead-letter → re-drive catches up (FS-01: "retry drains on recovery").
 * This is the [D10] close of Doc 09 T-05 ("index maintenance on Doc 10 publish events")
 * and FR-10 ("live Doc 10 articles").
 */
final class KbIndexMaintenanceConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'ai-background';
    }

    public function handle(PublishPipeline $pipeline): void
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first(['name', 'payload']);
        if ($event === null || $event->name !== 'kb.article.published') {
            return;
        }
        $payload = json_decode($event->payload, true);
        $variantId = is_array($payload) && is_string($payload['variant_id'] ?? null) ? $payload['variant_id'] : null;
        $versionId = is_array($payload) && is_string($payload['version_id'] ?? null) ? $payload['version_id'] : null;
        if ($variantId === null || $versionId === null) {
            return;
        }
        $result = $pipeline->index($variantId, $versionId);
        Log::info('kb.index.maintained', ['event_id' => $this->eventId, 'variant_id' => $variantId] + $result);
    }
}
