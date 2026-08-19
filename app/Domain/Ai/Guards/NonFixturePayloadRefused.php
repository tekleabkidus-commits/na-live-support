<?php

declare(strict_types=1);

namespace App\Domain\Ai\Guards;

use DomainException;

/**
 * The fixtures-only egress guard's refusal (S09 step 1; SESSION-09 subtle
 * part #1 — the Doc 05 PII law, mechanized).
 *
 * DELIBERATELY LEAK-PROOF: the message names the payload SLOT that failed
 * (role + index), never the content — the refused text may be exactly the
 * real customer text this guard exists to keep off the wire, so it must not
 * escape into an exception message that lands in a log.
 */
final class NonFixturePayloadRefused extends DomainException
{
    public static function forMessage(int|string $index, string $role): self
    {
        return new self(sprintf(
            'non-production AI egress refused: messages[%s] (role %s) carries no fixture tag (FixturesOnlyEgress::TAG). Real customer text never leaves a non-production environment.',
            $index,
            $role,
        ));
    }

    public static function forInput(int|string $index): self
    {
        return new self(sprintf(
            'non-production AI egress refused: input[%s] carries no fixture tag (FixturesOnlyEgress::TAG). Real customer text never leaves a non-production environment.',
            $index,
        ));
    }
}
