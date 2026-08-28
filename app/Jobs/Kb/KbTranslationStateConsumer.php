<?php

declare(strict_types=1);

namespace App\Jobs\Kb;

use App\Jobs\OutboxConsumer;
use Illuminate\Support\Facades\DB;

/**
 * Doc 10 FR-03 / NFR-05 (S10 step 8) — translation states are EVENT-DRIVEN, never a scan:
 * a publish of the article's SOURCE-language variant flips every OTHER published sibling
 * to `outdated` (BR-05: it still serves — only the badge changes); publishing a sibling
 * flips itself back to `current` at its own publish door. Durable (ADR 0011), idempotent
 * on event_id by construction: the flip is an absolute state write derived from truth.
 */
final class KbTranslationStateConsumer extends OutboxConsumer
{
    protected function queueFamily(): string
    {
        return 'ai-background';
    }

    public function handle(): void
    {
        /** @var object{name: string, payload: string}|null $event */
        $event = DB::table('events')->where('id', $this->eventId)->first(['name', 'payload']);
        if ($event === null || $event->name !== 'kb.article.published') {
            return;
        }
        $payload = json_decode($event->payload, true);
        if (! is_array($payload) || ! is_string($payload['article_id'] ?? null) || ! is_string($payload['language'] ?? null)) {
            return;
        }
        $source = DB::table('kb_articles')->where('id', $payload['article_id'])->value('source_language');
        if (! is_string($source) || $source !== $payload['language']) {
            return; // a non-source publish outdates nobody (FR-03)
        }
        DB::update(
            "UPDATE kb_article_variants SET translation_state = 'outdated', updated_at = clock_timestamp() WHERE article_id = ? AND language <> ? AND status = 'published'",
            [$payload['article_id'], $source],
        );
    }
}
