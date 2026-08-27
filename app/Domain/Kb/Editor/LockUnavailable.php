<?php

declare(strict_types=1);

namespace App\Domain\Kb\Editor;

use RuntimeException;

/** Doc 10 FS-05: Redis is down — the editor falls back to optimistic concurrency. */
final class LockUnavailable extends RuntimeException {}
