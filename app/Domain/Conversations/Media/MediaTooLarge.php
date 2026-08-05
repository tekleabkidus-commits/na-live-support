<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Media;

use RuntimeException;

/**
 * EC-02: the stream crossed the configured cap MID-DOWNLOAD and was aborted.
 * Terminal for the item (media_rejected), never retried — the file will not
 * get smaller.
 */
final class MediaTooLarge extends RuntimeException
{
    public function __construct(public readonly int $capBytes)
    {
        parent::__construct(sprintf('media stream exceeded the %d-byte cap mid-download (EC-02) and was aborted.', $capBytes));
    }
}
