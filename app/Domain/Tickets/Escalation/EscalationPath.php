<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Escalation;

use InvalidArgumentException;

/**
 * FR-23's escalation path, as a closed type: "path ∈ {supervisor_review,
 * department:{id}, named:{user}}". The kinds are platform-fixed — a new kind is a
 * spec change, not a parameter.
 */
final readonly class EscalationPath
{
    public const SUPERVISOR_REVIEW = 'supervisor_review';

    public const DEPARTMENT = 'department';

    public const NAMED = 'named';

    private function __construct(
        public string $kind,
        public ?string $targetId,
    ) {
        if (! in_array($kind, [self::SUPERVISOR_REVIEW, self::DEPARTMENT, self::NAMED], true)) {
            throw new InvalidArgumentException(sprintf('unknown escalation path "%s" (FR-23 fixes three).', $kind));
        }

        if ($kind !== self::SUPERVISOR_REVIEW && ($targetId === null || trim($targetId) === '')) {
            throw new InvalidArgumentException(sprintf('an escalation path "%s" names a target (FR-23).', $kind));
        }
    }

    public static function supervisorReview(): self
    {
        return new self(self::SUPERVISOR_REVIEW, null);
    }

    public static function department(string $departmentId): self
    {
        return new self(self::DEPARTMENT, $departmentId);
    }

    public static function named(string $userId): self
    {
        return new self(self::NAMED, $userId);
    }
}
