<?php

declare(strict_types=1);

namespace App\Support\GeoIp;

use MaxMind\Db\Reader;
use Throwable;

/**
 * In-process GeoIP city lookups (Doc 02 T-08, NFR-04, FS-04; ADR 0006).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE POSTURE IS THE POINT. GeoIP is an ENRICHMENT: NFR-04 forbids it from ever
 * blocking auth, and FS-04 fixes what degradation looks like — the device row
 * stores IP only and the screen says "location unavailable". So every failure
 * shape here (no path configured, file missing, file corrupt, lookup throwing
 * on a truncated data section) collapses to the same observable: null, plus the
 * named metric `auth.geoip.lookup_failed` so the outage is visible to operators
 * even though no user ever sees an error (R-02: fail-open paths masking
 * outages).
 *
 * T-08: the lookup is a local file read — never a network call. The Reader is
 * memoized per process because Octane workers are long-lived and re-parsing the
 * metadata per login would be waste; a FAILED open is deliberately not memoized,
 * so replacing a corrupt file heals on the next lookup without a deploy.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class GeoIpResolver
{
    private ?Reader $reader = null;

    private ?string $readerPath = null;

    public function locate(string $ip): ?GeoIpLocation
    {
        $path = $this->databasePath();

        if ($path === null) {
            // No database configured is a sanctioned posture (dev without the
            // file, Doc 18 not yet delivering) — quiet, not a metric: an alert
            // that fires on every login of every undeployed environment teaches
            // people to ignore the metric that matters.
            return null;
        }

        try {
            $record = $this->reader($path)->get($ip);
        } catch (Throwable $e) {
            $this->reader = null; // do not pin a broken handle — see docblock

            logger()->warning('GeoIP lookup failed; continuing without location', [
                'metric' => 'auth.geoip.lookup_failed',
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_array($record)) {
            return null; // address simply not in the database
        }

        $cityNames = [];
        if (isset($record['city']) && is_array($record['city'])
            && isset($record['city']['names']) && is_array($record['city']['names'])) {
            foreach ($record['city']['names'] as $locale => $name) {
                if (is_string($locale) && is_string($name)) {
                    $cityNames[$locale] = $name;
                }
            }
        }

        $countryCode = null;
        if (isset($record['country']) && is_array($record['country'])
            && is_string($record['country']['iso_code'] ?? null)) {
            $countryCode = $record['country']['iso_code'];
        }

        return new GeoIpLocation($cityNames, $countryCode);
    }

    private function reader(string $path): Reader
    {
        if ($this->reader !== null && $this->readerPath === $path) {
            return $this->reader;
        }

        $reader = new Reader($path);
        $this->reader = $reader;
        $this->readerPath = $path;

        return $reader;
    }

    private function databasePath(): ?string
    {
        $configured = config('services.geoip.database');

        if (! is_string($configured) || $configured === '') {
            return null;
        }

        return str_starts_with($configured, '/') ? $configured : base_path($configured);
    }
}
