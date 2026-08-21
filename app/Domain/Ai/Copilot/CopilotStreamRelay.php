<?php

declare(strict_types=1);

namespace App\Domain\Ai\Copilot;

use App\Domain\Conversations\Realtime\RealtimePusher;

/**
 * T-04's relay, Copilot half: provider deltas → the requesting agent's
 * Reverb USER channel (Doc 04 FR-25's one door; v:1, 32 KB, SEC-06 fence
 * all inherited). Frames are buffered to SENTENCE BOUNDARIES (§13: screen
 * readers announce at sentence boundaries, never token-by-token; §9:
 * reduced-motion renders in chunks — the same frames serve both).
 *
 * Events (closed, documented here — realtime is ephemeral fan-out, not the
 * bus, so the event catalog does not govern them; the Doc 07 realtime
 * contract test pins the shape):
 *   ai.copilot.delta  {request_id, seq, text}
 *   ai.copilot.card   {request_id, state, kind, artifact_id?, text?,
 *                      citations?, notices, kb?}
 */
final class CopilotStreamRelay
{
    public function __construct(private readonly RealtimePusher $pusher) {}

    public function open(string $userId, string $requestId): CopilotStream
    {
        return new CopilotStream($this->pusher, $userId, $requestId);
    }

    /** @param array<string, mixed> $card */
    public function card(string $userId, string $requestId, array $card): void
    {
        $this->pusher->toUser($userId, 'ai.copilot.card', ['request_id' => $requestId] + $card);
    }
}
