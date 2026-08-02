<?php

declare(strict_types=1);

namespace App\Domain\Auth\TwoFactor;

use RuntimeException;

/**
 * EC-10's designed refusal: this confirm belongs to a superseded enrolment.
 *
 * Distinct from InvalidEnrolmentCode on purpose — "your code was wrong" and
 * "another tab restarted enrolment; rescan the newest QR" require different
 * user actions, and collapsing them is exactly the confusion EC-10 names.
 */
final class EnrolmentSuperseded extends RuntimeException {}
