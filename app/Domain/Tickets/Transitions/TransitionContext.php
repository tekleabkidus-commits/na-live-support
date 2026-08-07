<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Transitions;

use App\Models\User;
use InvalidArgumentException;

/**
 * Who is transitioning, and why (FR-04). Immutable — a context is built at the edge of
 * a request or a job and travels unchanged into the one transaction.
 *
 * `signalMessageId` / `agentSignalMessageId` carry FR-24's first-response signals when
 * the same operation that transitions also produced the qualifying public reply (the
 * implicit-claim path of FR-05, for instance). They are written in the SAME statement
 * as the status change, so the trigger stamps both from one row version — which is what
 * FR-24's "computed transactionally with the transition" means.
 */
final readonly class TransitionContext
{
    public function __construct(
        public TransitionActor $actor,
        public ?User $user = null,
        public ?string $reason = null,
        public ?string $signalMessageId = null,
        public ?string $agentSignalMessageId = null,
    ) {
        if ($actor === TransitionActor::Staff && ! $user instanceof User) {
            throw new InvalidArgumentException('a staff transition needs the acting user — there is nothing to check a permission against otherwise (FR-04).');
        }
        if ($actor !== TransitionActor::Staff && $user instanceof User) {
            throw new InvalidArgumentException('a non-staff transition must not carry a user: recording a person as the actor of an automated change misattributes it (FR-04, SEC-03).');
        }
    }

    public static function staff(User $user, ?string $reason = null): self
    {
        return new self(TransitionActor::Staff, $user, $reason);
    }

    public static function system(?string $reason = null): self
    {
        return new self(TransitionActor::System, null, $reason);
    }
}
