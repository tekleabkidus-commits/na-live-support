<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Inbound;

use RuntimeException;

/**
 * A re-drive was attempted on a dead letter that is not parked — usually a
 * double-click or a second operator racing the first. The stable problem code
 * is `dead_letter_not_parked` (C-04); the row's history is untouched.
 */
final class DeadLetterNotParked extends RuntimeException
{
    public const CODE = 'dead_letter_not_parked';

    public function __construct(
        public readonly string $deadLetterId,
        public readonly string $status,
    ) {
        parent::__construct(sprintf(
            "dead letter %s is '%s', not parked — only parked items re-drive ('%s').",
            $deadLetterId,
            $status,
            self::CODE,
        ));
    }
}
