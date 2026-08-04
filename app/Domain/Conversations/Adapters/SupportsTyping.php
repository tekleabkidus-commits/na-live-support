<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * Capability extension: declared by `supports_typing` (Doc 04 FR-08/FR-18).
 *
 * The conformance suite enforces the pairing both ways: an adapter whose capabilities
 * say supports_typing MUST implement this; one that says false must refuse or omit it.
 * The CORE owns the ≤ every-4 s throttle (FR-18) — adapters just emit the signal.
 */
interface SupportsTyping
{
    public function sendTyping(string $externalChatId): void;
}
