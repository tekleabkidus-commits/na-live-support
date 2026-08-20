<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Providers\AllProvidersExhausted;
use App\Domain\Conversations\Storage\MessageRepository;

/**
 * FR-05's receipt half: a durable consumer of media.needs_ocr — extracts
 * {amount, currency, reference, timestamp, method?} WITH per-field
 * confidence and writes ocr_ref back through the Doc 04 door. The fields
 * land in the HANDOFF PACKET for the agent's own verification (EC-03: the
 * honesty rule — AI restates the claim, a human decides any credit).
 * Same FS-03 + C-09 posture as the transcription consumer it extends.
 */
final class OcrReceiptConsumer extends MediaHookConsumer
{
    public function handle(AiOrchestrator $orchestrator, MessageRepository $messages): void
    {
        $context = $this->mediaContext('media.needs_ocr');
        if ($context === null) {
            return;
        }
        [$message, $index, $item] = $context;

        try {
            $result = $orchestrator->execute('ocr_receipt', [
                ['role' => 'system', 'content' => 'Extract receipt fields from the referenced image. Return ONLY JSON: {"amount": {"value": ..., "confidence": 0..1}, "currency": {...}, "reference": {...}, "timestamp": {...}, "method": {...}}'],
                ['role' => 'user', 'content' => '[FIXTURE-SEAM] storage_key: '.(is_string($item['storage_key'] ?? null) ? $item['storage_key'] : '')],
            ]);

            $decoded = json_decode((string) $result['response']->content, true);
            if (! is_array($decoded) || $decoded === []) {
                return; // unusable extraction = absence (EC-03's agent verifies from the image)
            }

            $messages->updateMediaItem($message, $index, ['ocr_ref' => ['fields' => $decoded, 'provider' => $result['provider']]]);
        } catch (AllProvidersExhausted $outage) {
            throw $outage; // FS-03 via the queue's own retry/dead-letter path
        }
    }
}
