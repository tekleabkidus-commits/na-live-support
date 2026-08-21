<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use RuntimeException;

/**
 * T-09's DETERMINISTIC record/replay driver — what the golden-conversation
 * and red-team suites run against in CI (the nightly staging leg runs the
 * same suites against the live provider instead).
 *
 * Recordings are FIXTURE FILES (fixtures/ai/recordings/*.json), each:
 *   { "name": "...", "match": {"contains": "<substring of the LAST user
 *     message>"}, "response": {"content": "...", "tokens_in": n,
 *     "tokens_out": n} }
 * First match wins, filename-ordered — deterministic by construction, and
 * a fixture author reads the match rule in the file itself. No match is a
 * LOUD failure: a golden suite silently answering the wrong recording
 * would pin nothing.
 *
 * The egress guard applies here exactly as on the real client: replay
 * traffic is still payload traffic, and a suite that leaks real text into
 * fixtures should refuse the same way staging would.
 */
final class ReplayDriver implements ProviderDriver
{
    public function __construct(
        private readonly FixturesOnlyEgress $guard,
        private readonly ?string $recordingsPath = null,
    ) {}

    public function complete(array $payload): ProviderResponse
    {
        $this->guard->assertAllowed($payload);

        $needle = $this->lastUserContent($payload);

        foreach ($this->recordings() as $file => $recording) {
            $match = is_array($recording['match'] ?? null) ? $recording['match'] : [];
            $contains = $match['contains'] ?? null;
            if (! is_string($contains) || ! str_contains($needle, $contains)) {
                continue;
            }

            $response = is_array($recording['response'] ?? null) ? $recording['response'] : [];
            $tokensIn = $response['tokens_in'] ?? 0;
            $tokensOut = $response['tokens_out'] ?? 0;

            return new ProviderResponse(
                content: is_string($response['content'] ?? null) ? $response['content'] : null,
                tokensIn: is_numeric($tokensIn) ? (int) $tokensIn : 0,
                tokensOut: is_numeric($tokensOut) ? (int) $tokensOut : 0,
                raw: ['replayed' => $file],
            );
        }

        throw new RuntimeException(sprintf(
            'no recording matches the last user message (%d recordings scanned) — author one in fixtures/ai/recordings (T-09)',
            count($this->recordings()),
        ));
    }

    /** T-04 posture: the recording replays in sentence-boundary chunks. */
    public function stream(array $payload, callable $onDelta): ProviderResponse
    {
        $response = $this->complete($payload);
        foreach (SentenceChunker::chunks((string) $response->content) as $chunk) {
            $onDelta($chunk);
        }

        return $response;
    }

    public function embeddings(array $payload): ProviderResponse
    {
        $this->guard->assertAllowed($payload);

        // Deterministic fixture vectors: retrieval correctness proofs seed
        // their own vectors (ADR 0018's determinism split); the replay
        // driver answers a stable zero-vector so no suite silently depends
        // on replayed embedding VALUES.
        $inputs = $payload['input'] ?? [];
        $count = is_array($inputs) ? count($inputs) : 1;

        return new ProviderResponse(
            content: null,
            tokensIn: 0,
            tokensOut: 0,
            raw: ['replayed' => 'zero-vectors'],
            vectors: array_fill(0, max(1, $count), array_fill(0, 1024, 0.0)),
        );
    }

    /** @return array<string, array<string, mixed>> filename => recording, sorted */
    private function recordings(): array
    {
        $path = $this->recordingsPath ?? base_path('fixtures/ai/recordings');
        $files = glob($path.'/*.json') ?: [];
        sort($files);

        $recordings = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                continue;
            }
            $recording = [];
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $recording[$key] = $value;
                }
            }
            $recordings[basename($file)] = $recording;
        }

        return $recordings;
    }

    /** @param array<string, mixed> $payload */
    private function lastUserContent(array $payload): string
    {
        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if (is_array($message) && ($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                return $message['content'];
            }
        }

        return '';
    }
}
