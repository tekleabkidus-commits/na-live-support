<?php

declare(strict_types=1);

namespace App\Domain\Ai\Copilot;

use App\Domain\Ai\DecisionLog\DecisionRecord;
use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Ai\DecisionLog\OutcomeEnrichment;
use App\Domain\Ai\Prompt\ImmutableLayer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * FR-16: EVERY Copilot artifact the agent can use is a decision row
 * (surface=copilot) — no second store. Its text lives in response_payload
 * (SEC-06's 30-day window covers the agent's working life of a draft a
 * thousand times over); its OUTCOME is T-08's enrichment column through
 * the ONE shared pen (OutcomeEnrichment — the closed category list):
 *
 *   inserted_unedited  — the agent sent the artifact byte-for-byte
 *   inserted_edited    — the agent changed it; diff_ref = identifiers +
 *                        NUMERIC edit metrics (the message holds the final
 *                        text, this row holds the original — the diff is
 *                        derivable from the two ids; Q7: no text on the
 *                        enrichment column)
 *   discarded          — the agent dismissed it
 *
 * `insert` flips to inserted_unedited first (the insertion IS the use);
 * `sent` upgrades to inserted_edited when the sent body differs. Both are
 * the agent's OWN artifacts: a row another user minted is invisible to
 * these doors (the requester id is the filter, RLS the tenant backstop).
 */
final class CopilotArtifacts
{
    public function __construct(
        private readonly DecisionWriter $decisions,
        private readonly OutcomeEnrichment $outcomes,
    ) {}

    /**
     * @param  array<string, string>  $layerHashes
     * @param  array<string, mixed>  $meta  identifiers/categories + the artifact text
     * @param  array<string, mixed>|null  $requestPayload
     * @param  string|null  $action  defaults to artifact (usable) / refused (error); low_confidence and low_confidence_locked are the panel's non-usable states
     */
    public function mint(
        string $task,
        string $kind,
        string $requesterId,
        string $conversationId,
        ?string $messageId,
        string $language,
        ?string $text,
        string $provider,
        string $model,
        array $layerHashes,
        int $tokensIn,
        int $tokensOut,
        int $latencyMs,
        ?string $errorCategory = null,
        array $meta = [],
        ?array $requestPayload = null,
        ?string $ticketId = null,
        ?string $action = null,
        ?string $intent = null,
    ): string {
        $id = (string) new UuidV7;

        $this->decisions->record(new DecisionRecord(
            id: $id,
            surface: 'copilot',
            task: $task,
            provider: $provider,
            model: $model,
            layerHashes: $layerHashes === [] ? ['immutable' => ImmutableLayer::hash()] : $layerHashes,
            conversationId: $conversationId,
            ticketId: $ticketId,
            messageId: $messageId,
            language: $language,
            intent: $intent,
            action: $action ?? ($errorCategory === null ? 'artifact' : 'refused'),
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            latencyMs: $latencyMs,
            errorCategory: $errorCategory,
            requestPayload: $requestPayload,
            responsePayload: $meta + ['kind' => $kind, 'text' => $text, 'requester_id' => $requesterId],
        ));
        $this->decisions->flush();

        return $id;
    }

    /**
     * The requester's own artifact, or null (another user's, another
     * tenant's, a non-artifact row — all the same null).
     *
     * @return array{id: string, task: string, kind: string, text: string, conversation_id: string, outcome: ?string, meta: array<string, mixed>}|null
     */
    public function find(string $artifactId, string $requesterId): ?array
    {
        /** @var object{id: string, task: string, conversation_id: ?string, outcome: ?string, response_payload: ?string}|null $row */
        $row = DB::table('ai_decisions')
            ->where('id', $artifactId)
            ->where('surface', 'copilot')
            ->where('action', 'artifact')
            ->first(['id', 'task', 'conversation_id', 'outcome', 'response_payload']);

        if ($row === null || ! is_string($row->response_payload) || ! is_string($row->conversation_id)) {
            return null;
        }
        $decoded = json_decode($row->response_payload, true);
        if (! is_array($decoded) || ($decoded['requester_id'] ?? null) !== $requesterId || ! is_string($decoded['text'] ?? null)) {
            return null;
        }
        $meta = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $meta[$key] = $value;
            }
        }

        return [
            'id' => $row->id,
            'task' => $row->task,
            'kind' => is_string($meta['kind'] ?? null) ? $meta['kind'] : $row->task,
            'text' => $decoded['text'],
            'conversation_id' => $row->conversation_id,
            'outcome' => $row->outcome,
            'meta' => $meta,
        ];
    }

    /** FR-16: insertion = use; the outcome starts as unedited (sent() may upgrade it). */
    public function inserted(string $artifactId, string $requesterId): bool
    {
        $artifact = $this->find($artifactId, $requesterId);
        if ($artifact === null) {
            return false;
        }

        return $this->outcomes->enrich($artifactId, 'inserted_unedited', ['artifact_id' => $artifactId]);
    }

    /**
     * FR-16's edited/unedited verdict at SEND time: the sent message's body
     * (the agent's message, tenant-scoped by RLS) against the artifact's
     * text. diff_ref carries identifiers + numeric metrics only.
     *
     * @return 'inserted_unedited'|'inserted_edited'|null
     */
    public function sent(string $artifactId, string $requesterId, string $messageId): ?string
    {
        $artifact = $this->find($artifactId, $requesterId);
        if ($artifact === null) {
            return null;
        }

        /** @var object{body: ?string, conversation_id: string, author_id: ?string}|null $message */
        $message = DB::table('messages')->where('id', $messageId)->where('direction', 'out')->first(['body', 'conversation_id', 'author_id']);
        if ($message === null || $message->conversation_id !== $artifact['conversation_id'] || $message->author_id !== $requesterId) {
            return null;
        }

        $final = (string) $message->body;
        if ($final === $artifact['text']) {
            $this->outcomes->enrich($artifactId, 'inserted_unedited', ['artifact_id' => $artifactId, 'message_id' => $messageId]);

            return 'inserted_unedited';
        }

        $this->outcomes->enrich($artifactId, 'inserted_edited', [
            'artifact_id' => $artifactId,
            'diff_ref' => [
                'message_id' => $messageId,
                'original_length' => mb_strlen($artifact['text']),
                'final_length' => mb_strlen($final),
                'edit_distance' => levenshtein(
                    mb_substr($artifact['text'], 0, 255),
                    mb_substr($final, 0, 255),
                ),
            ],
        ]);

        return 'inserted_edited';
    }

    public function discarded(string $artifactId, string $requesterId): bool
    {
        $artifact = $this->find($artifactId, $requesterId);
        if ($artifact === null) {
            return false;
        }

        return $this->outcomes->enrich($artifactId, 'discarded', ['artifact_id' => $artifactId]);
    }
}
