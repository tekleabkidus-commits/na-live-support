<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

use App\Domain\Conversations\Messages\MessageKind;
use DomainException;

/**
 * One inbound media item as the adapter reports it (FR-08). `sourceUrl` is
 * ADAPTER-PROVIDED and therefore hostile input: the media fetch job downloads it only
 * through the allowlisted egress proxy (SEC-03), streamed with in-stream size
 * enforcement (T-04/EC-02), and nothing is delivered unscanned (AC-05/EC-11).
 */
final class InboundMedia
{
    public function __construct(
        public readonly MessageKind $kind,
        public readonly string $sourceUrl,
        public readonly string $mime,
        public readonly ?int $bytes = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $durationSeconds = null,
    ) {
        if (! in_array($this->kind, [
            MessageKind::Photo, MessageKind::Video, MessageKind::Voice,
            MessageKind::Audio, MessageKind::Document, MessageKind::Sticker,
        ], true)) {
            throw new DomainException("'{$this->kind->value}' is not a media kind.");
        }
    }
}
