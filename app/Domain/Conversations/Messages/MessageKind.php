<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Messages;

/**
 * Message kinds (Doc 04 FR-05).
 *
 * `system` (join/leave/window-expired/…) and `note` (internal, NEVER delivered to a
 * customer — SEC-07/BR-01/N-04) are message kinds but not deliverable kinds: the
 * deliverable subset is the SEPARATE {@see \App\Domain\Conversations\Adapters\DeliverableKind}
 * enum, and the absence of Note/System there is the type-level half of AC-04.
 *
 * Location and contact kinds are RESERVED by FR-05 and deliberately absent — adding
 * one is a contract change, not a gap.
 */
enum MessageKind: string
{
    case Text = 'text';
    case Photo = 'photo';
    case Video = 'video';
    case Voice = 'voice';
    case Audio = 'audio';
    case Document = 'document';
    case Sticker = 'sticker';
    case System = 'system';
    case Note = 'note';
}
