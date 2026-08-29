<?php

declare(strict_types=1);

namespace App\Jobs\Kb;

use App\Domain\Kb\Search\KbSearchDocuments;
use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * Doc 10 FR-17 (S10 step 10): the DURABLE keyword indexer (ADR 0011; contract fixture
 * doc10-events.json) on kb.article.published and kb.retrieval.updated (retirement) — each
 * delivery rewrites the variant's document FROM TRUTH (idempotent, C-09). Meilisearch down →
 * throws → tries ×3 → dead-letter → re-drive (FS-02: retrieval is unaffected; the two fail
 * independently). Drafts reach the index through the doors (ArticleWriter / WorkingCopy call
 * KbSearchDocuments after their commits — [step 12 UI] until then the manager list is DB-backed).
 */
final class KbSearchIndexer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'automation';
    }

    public function handle(KbSearchDocuments $documents): void
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first(['name', 'payload']);
        if ($event === null || ! in_array($event->name, ['kb.article.published', 'kb.retrieval.updated'], true)) {
            return;
        }
        $payload = json_decode($event->payload, true);
        if (! is_array($payload) || ! is_string($payload['variant_id'] ?? null)) {
            return;
        }
        $documents->writeFor($payload['variant_id']);
    }
}
