<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * THE THIN OPENAI-COMPATIBLE CLIENT (ADR 0016 — the ADR 0008/0012 ~300-line
 * precedent; no vendor SDK).
 *
 * EVERY REQUEST, WITHOUT EXCEPTION:
 *   1. passes the fixtures-only egress guard (S09 step 1's mechanism — the
 *    Doc 05 PII law; OrchestratorTest asserts no egress path skips it);
 *   2. rides the egress proxy when one is configured (CLAUDE.md §3's rule;
 *      the in-cluster embeddings sidecar configures none — ADR 0018);
 *   3. resolves its key AT CALL TIME from the vault ref (C-13/SEC-01; the
 *      A-19 interim) — the key exists only inside this method's frame.
 *
 * Timeouts are per-task (FR-01's task definitions); retry policy is the
 * FALLBACK CHAIN's concern, never a hidden HTTP retry here — a retry the
 * breaker cannot see is a breaker that cannot trip.
 */
final class OpenAiCompatibleClient implements ProviderDriver
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly FixturesOnlyEgress $guard,
        private readonly string $baseUrl,
        private readonly ?string $key,
        private readonly ?string $proxy,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function complete(array $payload): ProviderResponse
    {
        $this->guard->assertAllowed($payload);

        $response = $this->request()->post($this->baseUrl.'/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('provider error: HTTP %d from %s', $response->status(), $this->baseUrl));
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $content = null;
        $choices = $body['choices'] ?? null;
        if (is_array($choices) && is_array($choices[0] ?? null)) {
            $message = $choices[0]['message'] ?? null;
            if (is_array($message) && is_string($message['content'] ?? null)) {
                $content = $message['content'];
            }
        }

        [$tokensIn, $tokensOut] = $this->usage($body);

        return new ProviderResponse(
            content: $content,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            raw: $this->stringKeyed($body),
        );
    }

    /**
     * T-04's provider side: `stream: true` + SSE. Each `data:` frame's
     * content delta reaches $onDelta in arrival order; `[DONE]` ends the
     * stream; usage rides the final frame when the provider honors
     * stream_options.include_usage (OpenAI-compatible convention) — absent
     * usage is recorded as 0 with `usage_missing` in raw so T-07's metering
     * gap is visible, never silently invented.
     *
     * The guard, proxy, and at-call-time key resolution are EXACTLY
     * complete()'s — one request() builder, no second egress path.
     */
    public function stream(array $payload, callable $onDelta): ProviderResponse
    {
        $this->guard->assertAllowed($payload);

        $payload['stream'] = true;
        $payload['stream_options'] = ['include_usage' => true];

        $response = $this->request()
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->withOptions(['stream' => true])
            ->post($this->baseUrl.'/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('provider error: HTTP %d from %s', $response->status(), $this->baseUrl));
        }

        $content = '';
        $usage = null;
        $finishReason = null;
        $body = $response->toPsrResponse()->getBody();
        $carry = '';

        while (! $body->eof()) {
            $carry .= $body->read(8192);
            while (($nl = strpos($carry, "\n")) !== false) {
                $line = rtrim(substr($carry, 0, $nl), "\r");
                $carry = substr($carry, $nl + 1);
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }
                $frame = json_decode($data, true);
                if (! is_array($frame)) {
                    continue;
                }
                if (is_array($frame['usage'] ?? null)) {
                    $usage = $frame;
                }
                $choices = $frame['choices'] ?? null;
                if (! is_array($choices) || ! is_array($choices[0] ?? null)) {
                    continue;
                }
                $delta = $choices[0]['delta'] ?? null;
                if (is_array($delta) && is_string($delta['content'] ?? null) && $delta['content'] !== '') {
                    $content .= $delta['content'];
                    $onDelta($delta['content']);
                }
                if (is_string($choices[0]['finish_reason'] ?? null)) {
                    $finishReason = $choices[0]['finish_reason'];
                }
            }
        }

        [$tokensIn, $tokensOut] = $usage === null ? [0, 0] : $this->usage($usage);

        return new ProviderResponse(
            content: $content,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            raw: [
                'streamed' => true,
                'usage_missing' => $usage === null,
                'choices' => [['finish_reason' => $finishReason, 'message' => ['content' => $content]]],
            ],
        );
    }

    public function embeddings(array $payload): ProviderResponse
    {
        $this->guard->assertAllowed($payload);

        $response = $this->request()->post($this->baseUrl.'/embeddings', $payload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('provider error: HTTP %d from %s', $response->status(), $this->baseUrl));
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $vectors = [];
        $data = $body['data'] ?? null;
        if (is_array($data)) {
            foreach ($data as $row) {
                if (! is_array($row) || ! is_array($row['embedding'] ?? null)) {
                    continue;
                }
                $vector = [];
                foreach ($row['embedding'] as $component) {
                    $vector[] = is_numeric($component) ? (float) $component : 0.0;
                }
                $vectors[] = $vector;
            }
        }

        [$tokensIn] = $this->usage($body);

        return new ProviderResponse(
            content: null,
            tokensIn: $tokensIn,
            tokensOut: 0,
            raw: $this->stringKeyed($body),
            vectors: $vectors,
        );
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return array{0: int, 1: int} [prompt_tokens, completion_tokens]
     */
    private function usage(array $body): array
    {
        $usage = $body['usage'] ?? null;
        $usage = is_array($usage) ? $usage : [];
        $in = $usage['prompt_tokens'] ?? 0;
        $out = $usage['completion_tokens'] ?? 0;

        return [is_numeric($in) ? (int) $in : 0, is_numeric($out) ? (int) $out : 0];
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return array<string, mixed>
     */
    private function stringKeyed(array $body): array
    {
        $keyed = [];
        foreach ($body as $key => $value) {
            if (is_string($key)) {
                $keyed[$key] = $value;
            }
        }

        return $keyed;
    }

    private function request(): PendingRequest
    {
        $request = $this->http->timeout($this->timeoutSeconds)->acceptJson()->asJson();

        if ($this->proxy !== null && $this->proxy !== '') {
            $request = $request->withOptions(['proxy' => $this->proxy]);
        }

        if ($this->key !== null) {
            if ($this->key === '') {
                throw new RuntimeException('the vault ref resolved to an empty key — provider unusable (C-13)');
            }
            $request = $request->withToken($this->key);
        }

        return $request;
    }
}
