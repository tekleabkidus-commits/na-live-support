<?php

declare(strict_types=1);

namespace App\Support\Auth\Devices;

use Illuminate\Http\Request;

/**
 * T-03's device fingerprint: hash(UA family + platform + accept-language +
 * stable client hints).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS AND IS NOT. The fingerprint recognises a MACHINE across logins
 * so that FR-13's remember-me can bind to it and T-03's notice can fire once
 * per new device. It hashes only headers the browser volunteers on every
 * request — UA, Accept-Language, and the LOW-ENTROPY client hints browsers send
 * unprompted (Sec-CH-UA, Sec-CH-UA-Platform, Sec-CH-UA-Mobile). No canvas, no
 * fonts, no hardware probing: the devices migration records why (N-06's spirit
 * applies to staff too). The hash is stored; the components are not
 * reconstructable from it.
 *
 * THE FAMILY, NOT THE VERSION. Chrome ships a new major every four weeks; a
 * fingerprint over the full UA string would make every browser update a "new
 * device" and train users to ignore the notice that exists to catch attackers.
 *
 * THE LABEL IS DATA, NOT COPY (N-03). "Chrome · Windows" contains no
 * translatable words — the product names come from the user's own UA and the
 * separator is punctuation. Anything sentence-shaped on the devices screen
 * belongs to the i18n layer, not here.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DeviceFingerprint
{
    private function __construct(
        public readonly string $hash,
        public readonly string $label,
        public readonly ?string $userAgent,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $userAgent = $request->userAgent();
        $family = self::family($userAgent ?? '');
        $platform = self::platform($userAgent ?? '');

        $components = [
            $family,
            $platform ?? '',
            (string) $request->header('Accept-Language', ''),
            (string) $request->header('Sec-CH-UA', ''),
            (string) $request->header('Sec-CH-UA-Platform', ''),
            (string) $request->header('Sec-CH-UA-Mobile', ''),
        ];

        return new self(
            hash: hash('sha256', implode('|', $components)),
            label: $platform === null ? $family : $family.' · '.$platform,
            userAgent: $userAgent,
        );
    }

    /**
     * Order matters: Chrome's UA contains "Safari", Edge's contains "Chrome",
     * Opera's contains both. Most-specific token first.
     */
    private static function family(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            $userAgent === '' => 'Unknown',
            default => 'Other',
        };
    }

    private static function platform(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Macintosh'), str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
