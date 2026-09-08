<?php

declare(strict_types=1);

namespace App\Domain\Sms\Encoding;

/**
 * The segmenter's answer: the detected encoding, the unit count (septets or
 * UTF-16 code units), the segment count, the per-segment capacity that
 * applied (160/70 single, 153/67 concatenated — A-66), and the boundaries as
 * unit offsets for the editor's inline markers ("a faint rule at each
 * segment break", §9).
 */
final readonly class Segmentation
{
    /**
     * @param  list<int>  $boundaries
     */
    public function __construct(
        public Encoding $encoding,
        public int $units,
        public int $segments,
        public int $perSegment,
        public array $boundaries,
    ) {}
}
