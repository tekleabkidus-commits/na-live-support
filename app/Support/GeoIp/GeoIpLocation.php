<?php

declare(strict_types=1);

namespace App\Support\GeoIp;

/**
 * One resolved GeoIP answer (Doc 02 T-08, §14).
 *
 * City names are carried per locale because §14 wants "place names localized
 * where data allows, English fallback" — the caller picks with cityFor(), and
 * a locale the data lacks falls back to English rather than to nothing.
 */
final class GeoIpLocation
{
    /**
     * @param  array<string, string>  $cityNames  locale => name
     */
    public function __construct(
        public readonly array $cityNames,
        public readonly ?string $countryCode,
    ) {}

    public function cityFor(string $locale): ?string
    {
        return $this->cityNames[$locale] ?? $this->cityNames['en'] ?? null;
    }
}
