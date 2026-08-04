<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

use App\Domain\Conversations\Messages\MessageKind;
use DomainException;

/**
 * The kinds that may leave the platform toward a customer — Doc 04 SEC-07's physics.
 *
 * This enum is the load-bearing absence: there is NO Note case and NO System case, so a
 * DeliverablePayload (the only thing ChannelAdapter::send accepts) cannot be built from
 * staff-internal content at the type level (SEC-07, BR-01, AC-04, invariant N-04).
 * tests/Security/NoteIsolationTest pins the case list; adding Note here is the exact
 * defect that test exists to catch.
 */
enum DeliverableKind: string
{
    case Text = 'text';
    case Photo = 'photo';
    case Video = 'video';
    case Voice = 'voice';
    case Audio = 'audio';
    case Document = 'document';
    case Sticker = 'sticker';

    /**
     * The single sanctioned MessageKind → DeliverableKind conversion point.
     *
     * Note and System kinds have no image in this map and throw — that refusal is the
     * runtime half of AC-04's guard at the earliest possible boundary. EC-02's
     * customer-facing notices are ADAPTER-TEMPLATED payloads, never System messages
     * routed outward.
     */
    public static function fromMessageKind(MessageKind $kind): self
    {
        return match ($kind) {
            MessageKind::Text => self::Text,
            MessageKind::Photo => self::Photo,
            MessageKind::Video => self::Video,
            MessageKind::Voice => self::Voice,
            MessageKind::Audio => self::Audio,
            MessageKind::Document => self::Document,
            MessageKind::Sticker => self::Sticker,
            MessageKind::System, MessageKind::Note => throw new DomainException(
                "message kind '{$kind->value}' is not deliverable (SEC-07/BR-01): "
                .'staff-internal content has no adapter path.',
            ),
        };
    }
}
