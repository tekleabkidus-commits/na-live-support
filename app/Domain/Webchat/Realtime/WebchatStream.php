<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Realtime;

use App\Domain\Ai\Providers\SentenceChunker;

/**
 * Doc 13 FR-12 / §8 / Doc 09 T-04's widget half — the visitor's stream sink:
 * sentence-batched frames (`webchat.delta {turn_ref, seq, text}`) on the
 * visitor's private `thread` channel; `restart` clears the caret (a repair
 * retry or a handoff), `close` flushes the tail. The FINAL text is always the
 * Doc 04 outbound row; a lost frame is the cursor endpoint's recovery. Wired
 * into the chatbot turn ONLY when P-STREAM lands (countersigned) — until
 * then this class is proven standalone.
 */
final class WebchatStream
{
    private string $buffer = '';

    private int $seq = 0;

    public function __construct(
        private readonly WebchatPusher $pusher,
        private readonly string $visitorId,
        private readonly string $turnRef,
    ) {}

    public function delta(string $text): void
    {
        $this->buffer .= $text;
        [$sentences, $rest] = SentenceChunker::split($this->buffer);
        $this->buffer = $rest;
        foreach ($sentences as $sentence) {
            if ($sentence !== '') {
                $this->emit($sentence);
            }
        }
    }

    public function restart(): void
    {
        $this->buffer = '';
        $this->seq = 0;
        $this->pusher->toVisitor($this->visitorId, WebchatPusher::SUFFIX_THREAD, 'webchat.delta', ['turn_ref' => $this->turnRef, 'seq' => 0, 'text' => '', 'restart' => true]);
    }

    public function close(): void
    {
        if (trim($this->buffer) !== '') {
            $this->emit($this->buffer);
        }
        $this->buffer = '';
        $this->pusher->toVisitor($this->visitorId, WebchatPusher::SUFFIX_THREAD, 'webchat.delta', ['turn_ref' => $this->turnRef, 'seq' => ++$this->seq, 'text' => '', 'done' => true]);
    }

    private function emit(string $text): void
    {
        $this->pusher->toVisitor($this->visitorId, WebchatPusher::SUFFIX_THREAD, 'webchat.delta', ['turn_ref' => $this->turnRef, 'seq' => ++$this->seq, 'text' => $text]);
    }
}
