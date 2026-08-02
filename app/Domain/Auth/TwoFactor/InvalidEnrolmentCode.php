<?php

declare(strict_types=1);

namespace App\Domain\Auth\TwoFactor;

use RuntimeException;

/**
 * FR-06: the activation code did not verify against the pending secret.
 */
final class InvalidEnrolmentCode extends RuntimeException {}
