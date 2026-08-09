<?php

declare(strict_types=1);

namespace Tests\Support\Adapters;

use App\Domain\Telegram\Api\TelegramApi;
use Throwable;

/**
 * The conformance suite's TelegramApi (AdapterConformanceCase: "never depends
 * on a mock HTTP service being up"). Deterministic success by default;
 * `script()` queues a Throwable (or a result array) consumed by the next
 * call — the same scripting idea as FakeChannelAdapter::scriptResult, one
 * layer lower so the REAL TelegramAdapter code runs above it.
 */
final class ScriptedTelegramApi implements TelegramApi
{
    /** @var list<array{token: string, method: string, params: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<Throwable|array<array-key, mixed>> */
    private array $script = [];

    private int $nextMessageId = 5000;

    /** @param Throwable|array<array-key, mixed> $next */
    public function script(Throwable|array $next): void
    {
        $this->script[] = $next;
    }

    public function call(string $botToken, string $method, array $params = []): array
    {
        $this->calls[] = ['token' => $botToken, 'method' => $method, 'params' => $params];

        if ($this->script !== []) {
            $next = array_shift($this->script);
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }

        return ['message_id' => $this->nextMessageId++];
    }

    public function fileUrl(string $botToken, string $filePath): string
    {
        return sprintf('scripted://file/bot%s/%s', $botToken, $filePath);
    }
}
