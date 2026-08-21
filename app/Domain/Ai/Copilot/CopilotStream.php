<?php

declare(strict_types=1);

namespace App\Domain\Ai\Copilot;

use App\Domain\Ai\Providers\SentenceChunker;
use App\Domain\Conversations\Realtime\RealtimePusher;

/** One in-flight stream: buffer → sentence frames → flush on close. */
final class CopilotStream
{
    private string $buffer = '';

    private int $seq = 0;

    public function __construct(
        private readonly RealtimePusher $pusher,
        private readonly string $userId,
        private readonly string $requestId,
    ) {}

    public function delta(string $text): void
    {
        $this->buffer .= $text;
        [$complete, $rest] = SentenceChunker::split($this->buffer);
        $this->buffer = $rest;
        foreach ($complete as $sentence) {
            $this->emit($sentence);
        }
    }

    /** Mid-stream provider failover: the card restarts — tell the client to clear. */
    public function restart(): void
    {
        $this->buffer = '';
        $this->seq = 0;
        $this->pusher->toUser($this->userId, 'ai.copilot.delta', ['request_id' => $this->requestId, 'seq' => 0, 'text' => '', 'restart' => true]);
    }

    /** restart() for call sites that pass the stream straight on. */
    public function restartAnd(): self
    {
        $this->restart();

        return $this;
    }

    public function close(): void
    {
        if ($this->buffer !== '') {
            $this->emit($this->buffer);
            $this->buffer = '';
        }
    }

    public function frames(): int
    {
        return $this->seq;
    }

    private function emit(string $sentence): void
    {
        $this->seq++;
        $this->pusher->toUser($this->userId, 'ai.copilot.delta', ['request_id' => $this->requestId, 'seq' => $this->seq, 'text' => $sentence]);
    }
}
