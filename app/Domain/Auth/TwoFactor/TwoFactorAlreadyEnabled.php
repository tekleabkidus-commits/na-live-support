<?php

declare(strict_types=1);

namespace App\Domain\Auth\TwoFactor;

use RuntimeException;

/**
 * begin() refused because 2FA is active: re-enrolment without proof would be
 * a code-free path to switching 2FA off (see TwoFactorEnrolment::begin).
 */
final class TwoFactorAlreadyEnabled extends RuntimeException {}
