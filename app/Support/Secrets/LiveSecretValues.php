<?php

declare(strict_types=1);

namespace App\Support\Secrets;

/**
 * P-SCRUB-LIVE (Doc 02 SEC-08 / C-13; Doc 14 R-D14-1.6, STOP-G — the
 * P-RIDER1-FIX shape generalized): the set of secret VALUES revealed in THIS
 * request or job, so `ScrubSecrets` can redact a secret whose SHAPE no regex
 * can name (a gateway token's format is vendor-private) wherever it lands —
 * under any key, inside the message — the moment it exists in memory.
 *
 * Laws (the countersign conditions): BOUNDED (≤ 32 values — older entries
 * fall away; a request revealing more secrets than that is itself the
 * anomaly); values shorter than 12 characters are not registered (an
 * exact-substring scrub on a short value would eat innocent text); CLEARED
 * at exactly the TenantContext clear points (TenantContext::clear() calls
 * clear() — the request's finally, the job middleware, RunsWithinTenant);
 * NOT INSTANTIABLE and never serialized — a private constructor and a
 * static set, so no object can carry the values into a job payload, a
 * cache, or a log.
 */
final class LiveSecretValues
{
    public const MAX_VALUES = 32;

    public const MIN_LENGTH = 12;

    /** @var list<string> */
    private static array $values = [];

    private function __construct() {}

    public static function register(string $plaintext): void
    {
        if (strlen($plaintext) < self::MIN_LENGTH || in_array($plaintext, self::$values, true)) {
            return;
        }
        self::$values[] = $plaintext;
        if (count(self::$values) > self::MAX_VALUES) {
            self::$values = array_slice(self::$values, -self::MAX_VALUES);
        }
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::$values;
    }

    public static function clear(): void
    {
        self::$values = [];
    }
}
