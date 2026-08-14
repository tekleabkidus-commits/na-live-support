<?php

declare(strict_types=1);

namespace App\Domain\Customers\Identity;

use RuntimeException;

/**
 * A-40 (RULED at the SCHEMA AMENDMENT 2 countersign): the runtime
 * channel→identity-type mapping is DOC 08-OWNED — the identity taxonomy
 * (telegram | web | phone | email, FR-04) is this document's domain model and
 * never rides Doc 04's frozen ChannelAdapter contract (option (a), rejected
 * with reasons in the ruling).
 *
 * FAIL-LOUD is the ruling's condition: an unmapped channel THROWS at the
 * resolver boundary, and ChannelIdentityMapTest asserts every channel the
 * AdapterRegistry knows has a mapping — drift is a red test, not a silent
 * default.
 */
final class ChannelIdentityMap
{
    /**
     * channel → FR-04 identity type. `webchat` reserved for Doc 13.
     *
     * `fake` / `fakechan` / `minchan` are the Doc 04 conformance-suite test
     * adapters (SS-02's second adapter included) — they simulate web-style
     * channels, so their identities type as `web`. Added when A-40's
     * fail-loud fired in the wild on the conformance gate's first run
     * through the rewired seam (the mechanism working, filed).
     */
    private const MAP = [
        'telegram' => 'telegram',
        'fake' => 'web',
        'fakechan' => 'web',
        'minchan' => 'web',
        'webchat' => 'web',
    ];

    public static function typeFor(string $channel): string
    {
        $type = self::MAP[$channel] ?? null;

        if ($type === null) {
            throw new RuntimeException(
                "ChannelIdentityMap knows no identity type for channel '{$channel}' — "
                .'extend the map deliberately (A-40; FR-04 owns the taxonomy).'
            );
        }

        return $type;
    }

    /** @return list<string> */
    public static function channels(): array
    {
        return array_keys(self::MAP);
    }
}
