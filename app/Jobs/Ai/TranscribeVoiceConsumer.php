<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Providers\AllProvidersExhausted;
use App\Domain\Conversations\Storage\MessageRepository;

/**
 * FR-05's voice half: a durable consumer of media.needs_transcript (Doc 04
 * T-05's hook) — transcribes and writes transcript_ref back through the
 * Doc 04 repository's own media-item door.
 *
 * FS-03's law: a provider outage leaves the item UNTOUCHED (the player
 * works, the transcript is absent) and the redelivery/retry path drains on
 * recovery — never a poisoned item, never a fake transcript. IDEMPOTENT ON
 * event_id: an item already carrying transcript_ref is left alone.
 *
 * V1 provider honesty (ADR 0019 note): no audio-transcription endpoint is
 * proven on the ruled provider; the task routes through the orchestrator's
 * 'fast' tier and the FS-03 path is the PROVEN behavior until a
 * transcription-capable provider config lands (a config change, zero
 * deploy — the seam is the deliverable here).
 */
final class TranscribeVoiceConsumer extends MediaHookConsumer
{
    public function handle(AiOrchestrator $orchestrator, MessageRepository $messages): void
    {
        $context = $this->mediaContext('media.needs_transcript');
        if ($context === null) {
            return;
        }
        [$message, $index, $item] = $context;

        try {
            $result = $orchestrator->execute('transcribe_voice', [
                ['role' => 'system', 'content' => 'Transcribe the referenced customer voice note. Return ONLY the transcript text.'],
                ['role' => 'user', 'content' => '[FIXTURE-SEAM] storage_key: '.(is_string($item['storage_key'] ?? null) ? $item['storage_key'] : '')],
            ]);

            $transcript = trim((string) $result['response']->content);
            if ($transcript === '') {
                return; // an empty transcript is FS-03's absence, not a write
            }

            $messages->updateMediaItem($message, $index, ['transcript_ref' => ['text' => $transcript, 'provider' => $result['provider']]]);
        } catch (AllProvidersExhausted $outage) {
            // FS-03: transcript unavailable — the item is untouched and the
            // PROPAGATED throw is the retry path (OutboxConsumer tries=3,
            // then dead-letter + re-drive — the FS-05 posture); recovery
            // drains the queue.
            throw $outage;
        }
    }
}
