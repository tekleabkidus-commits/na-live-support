<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

use InvalidArgumentException;

/**
 * The only content shape an adapter will ever serialize toward a customer
 * (Doc 04 SEC-07, BR-01, AC-04).
 *
 * Constructed exclusively through the named constructors below, each of which requires
 * a {@see DeliverableKind} — an enum with no Note/System case. That is the "type-level
 * construction" SEC-07 mandates: internal notes cannot produce this type, so no adapter
 * serialization path can ever carry one. Do not add a constructor that accepts a raw
 * kind string or a Message row; the conversion point is
 * {@see DeliverableKind::fromMessageKind()} and it refuses staff-internal kinds.
 */
final class DeliverablePayload
{
    /**
     * @param  list<OutboundMedia>  $media
     * @param  list<array{text: string, value: string}>  $buttons  rendered only where
     *                                                             Capabilities::$supportsButtons; core falls back per FR-09, never the adapter
     */
    private function __construct(
        public readonly DeliverableKind $kind,
        public readonly ?string $body,
        public readonly array $media,
        public readonly array $buttons,
    ) {}

    /**
     * @param  list<array{text: string, value: string}>  $buttons
     */
    public static function text(string $body, array $buttons = []): self
    {
        if ($body === '') {
            throw new InvalidArgumentException('a text payload requires a non-empty body.');
        }

        return new self(DeliverableKind::Text, $body, [], $buttons);
    }

    public static function media(DeliverableKind $kind, OutboundMedia $item, ?string $caption = null): self
    {
        if ($kind === DeliverableKind::Text) {
            throw new InvalidArgumentException('a media payload requires a media kind; use text().');
        }

        return new self($kind, $caption, [$item], []);
    }
}
