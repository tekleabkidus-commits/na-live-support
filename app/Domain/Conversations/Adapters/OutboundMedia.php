<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * One outbound media item, referenced by storage key — bytes stay in the media store
 * (Doc 04 T-04); adapters fetch/stream from the signed URL the core mints. Payloads on
 * the realtime bus reference media the same way (FR-25: never inlined).
 */
final class OutboundMedia
{
    public function __construct(
        public readonly string $storageKey,
        public readonly string $mime,
        public readonly int $bytes,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $durationSeconds = null,
    ) {}
}
