<?php

declare(strict_types=1);

namespace App\Domain\Ai\Copilot;

use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;

/**
 * Doc 09's SEND-TIME seam for the composer (the S09 step-10 COUNTERSIGNED
 * packet's Doc 09 half). The composer's reply door calls this with the
 * artifact id the agent inserted:
 *
 *  channelMetaForSend() — FR-15: for a TRANSLATE artifact returns the
 *    channel_meta fragment {ai_translation: {artifact_id, original_text,
 *    source_language, target_language}} so the agent's original is stored
 *    ON THE MESSAGE (the hover source, for the row's lifetime). Every
 *    refusal is a SILENT [] — the send proceeds unchanged: no permission
 *    (ai.copilot.insert_draft, FR-14's gate), not the caller's artifact,
 *    another conversation's artifact, a non-insertable row, a non-translate
 *    artifact (nothing to preserve).
 *  recordSent() — FR-16: the edited/unedited verdict against the sent row.
 *
 * This class SENDS NOTHING and holds no outbound dependency (BR-01's static
 * sweep covers it) — it answers the composer's questions, the composer sends.
 */
final class CopilotSendMeta
{
    public function __construct(
        private readonly CopilotArtifacts $artifacts,
        private readonly CapabilityMapProvider $verdicts,
    ) {}

    /** @return array<string, mixed> */
    public function channelMetaForSend(User $agent, string $artifactId, string $conversationId, string $body): array
    {
        if (! $this->verdicts->verdictFor($agent, 'ai.copilot.insert_draft')->allowed) {
            return [];
        }
        $artifact = $this->artifacts->find($artifactId, $agent->identifier());
        if ($artifact === null || $artifact['conversation_id'] !== $conversationId || $artifact['kind'] !== 'translate') {
            return [];
        }
        $meta = $artifact['meta'];
        if (! is_string($meta['original_text'] ?? null)) {
            return [];
        }

        return ['ai_translation' => [
            'artifact_id' => $artifact['id'],
            'original_text' => $meta['original_text'],
            'source_language' => is_string($meta['source_language'] ?? null) ? $meta['source_language'] : null,
            'target_language' => is_string($meta['target_language'] ?? null) ? $meta['target_language'] : null,
            'edited' => $body !== $artifact['text'],
        ]];
    }

    /** @return 'inserted_unedited'|'inserted_edited'|null */
    public function recordSent(User $agent, string $artifactId, string $messageId): ?string
    {
        if (! $this->verdicts->verdictFor($agent, 'ai.copilot.insert_draft')->allowed) {
            return null;
        }

        return $this->artifacts->sent($artifactId, $agent->identifier(), $messageId);
    }
}
