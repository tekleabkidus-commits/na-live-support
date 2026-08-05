<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Media;

/**
 * One clamd verdict (Doc 04 FR-10 step 5, AC-05). `Unavailable` is NOT a
 * verdict about the file — it is EC-11's branch: the scanner could not answer,
 * and the item must be HELD, never delivered ("nothing is ever delivered
 * unscanned" admits no default-allow).
 */
enum ScanVerdict: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Unavailable = 'unavailable';
}
