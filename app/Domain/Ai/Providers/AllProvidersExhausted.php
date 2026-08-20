<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use RuntimeException;

/**
 * T-01/FS-01: the whole fallback chain failed or sits tripped — DEGRADED
 * MODE. The caller maps this to the ladder (chatbot bypass-to-handoff,
 * Copilot degraded card, Supervisor pause — step 8); it never reaches a
 * customer as an error.
 */
final class AllProvidersExhausted extends RuntimeException {}
