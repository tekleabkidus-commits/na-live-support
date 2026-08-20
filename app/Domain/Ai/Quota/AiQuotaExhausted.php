<?php

declare(strict_types=1);

namespace App\Domain\Ai\Quota;

use RuntimeException;

/** EC-12: the pre-call gate refused — the caller routes straight to a human. */
final class AiQuotaExhausted extends RuntimeException {}
