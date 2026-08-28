<?php

declare(strict_types=1);

namespace App\Domain\Kb\Translation;

use RuntimeException;

/** §14: translate-all refused — the source variant's working copy carries no text blocks. */
final class SourceHasNoContent extends RuntimeException {}
