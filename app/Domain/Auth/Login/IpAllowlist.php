<?php

declare(strict_types=1);

namespace App\Domain\Auth\Login;

/**
 * Doc 02 SEC-05's matcher: a role's `ip_allowlist` (jsonb list) holds exact
 * IPs ("196.188.1.7", "2a01:db8::1") and CIDR ranges ("196.188.0.0/16",
 * "2a01:db8::/32"). NULL list = no restriction; an EMPTY list allows nothing
 * (an operator who saves an empty allowlist has said "nowhere is
 * trustworthy", and honoring it fail-closed is the only safe reading).
 * Malformed entries never match (fail closed, never open).
 */
final class IpAllowlist
{
    /**
     * @param  array<array-key, mixed>|null  $allowlist  jsonb content — validated per entry
     */
    public static function permits(?array $allowlist, string $ip): bool
    {
        if ($allowlist === null) {
            return true;
        }

        foreach ($allowlist as $entry) {
            /** @var mixed $entry jsonb content is attacker-shaped until proven */
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            if (self::matches($entry, $ip)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $entry, string $ip): bool
    {
        if (! str_contains($entry, '/')) {
            $a = @inet_pton($entry);
            $b = @inet_pton($ip);

            return $a !== false && $b !== false && $a === $b;
        }

        [$subnet, $bits] = explode('/', $entry, 2);
        if (! ctype_digit($bits)) {
            return false;
        }
        $prefix = (int) $bits;

        $subnetBin = @inet_pton($subnet);
        $ipBin = @inet_pton($ip);
        if ($subnetBin === false || $ipBin === false || strlen($subnetBin) !== strlen($ipBin)) {
            return false;
        }
        if ($prefix < 0 || $prefix > strlen($ipBin) * 8) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainderBits = $prefix % 8;

        if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainderBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainderBits) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
