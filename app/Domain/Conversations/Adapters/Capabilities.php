<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * A channel's capability envelope (Doc 04 FR-08).
 *
 * FR-09: these flags — never the channel's name — drive core behavior: composer limits
 * (maxText), media acceptance (mediaTypes), typing relay (supportsTyping, FR-18),
 * staff edit/delete (supportsEdit/supportsDelete, FR-22), button rendering fallbacks
 * (supportsButtons), and the reply window (replyWindow; FR-14's window_expired class,
 * composer state in Doc 05).
 *
 * $mediaTypes holds DeliverableKind values ('photo', 'voice', …): the capability
 * question a channel answers is "can I carry this KIND", not "this exact mime".
 */
final class Capabilities
{
    /**
     * @param  list<string>  $mediaTypes  DeliverableKind values
     */
    public function __construct(
        public readonly int $maxText,
        public readonly array $mediaTypes,
        public readonly bool $supportsTyping,
        public readonly bool $supportsEdit,
        public readonly bool $supportsDelete,
        public readonly bool $supportsButtons,
        public readonly ReplyWindow $replyWindow,
    ) {}

    public function allowsMediaKind(DeliverableKind $kind): bool
    {
        return in_array($kind->value, $this->mediaTypes, true);
    }
}
