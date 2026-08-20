<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chatbot;

/** A model action that passed the closed-enum schema (FR-08). */
final readonly class ValidatedAction
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $action,
        public array $payload,
    ) {}
}
