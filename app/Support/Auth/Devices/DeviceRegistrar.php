<?php

declare(strict_types=1);

namespace App\Support\Auth\Devices;

use App\Models\Device;
use App\Models\User;
use App\Support\GeoIp\GeoIpResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * The device upsert in the login flow (Doc 02 FR-04, FR-12, T-03, T-08, FS-04).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * One row per (user, fingerprint): a repeat login from a known machine UPDATES
 * last_* rather than growing the table, which is what makes "is this a new
 * device?" a lookup. The unique index is the arbiter — two concurrent first
 * logins from the same new machine race to insert, the loser catches the
 * violation and reloads the winner's row (the EC-05 idiom; neither login
 * fails, and `new` is true only for the one that actually created the row, so
 * T-03's notice cannot double-fire).
 *
 * THE CITY IS RESOLVED HERE AND STORED, per the migration's ruling: FS-04 wants
 * a corrupt database to degrade the ROW (IP only, null city), never the screen
 * render. The stored name prefers the logging-in user's locale with English
 * fallback (§14, "localized where data allows") — the device row's only reader
 * is its own user (FR-12 is self-service), so their locale at login is the
 * right one to store.
 *
 * T-03's informational NOTICE does not ship in this step: there is no
 * notification pipeline until Docs 07/14, and a setting nothing reads may not
 * enter the registry. `notified_at` stays null; the notice step owns both.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class DeviceRegistrar
{
    public function __construct(private readonly GeoIpResolver $geoIp) {}

    /**
     * @return array{device: Device, new: bool}
     */
    public function record(User $user, Request $request, CarbonImmutable $now): array
    {
        $fingerprint = DeviceFingerprint::fromRequest($request);
        $ip = $request->ip();
        $location = is_string($ip) ? $this->geoIp->locate($ip) : null;

        $locale = is_string($user->locale) && $user->locale !== '' ? $user->locale : 'en';
        $city = $location?->cityFor($locale);

        $existing = Device::query()
            ->where('user_id', $user->identifier())
            ->where('fingerprint_hash', $fingerprint->hash)
            ->first();

        if ($existing === null) {
            try {
                $device = Device::query()->create([
                    'user_id' => $user->identifier(),
                    'fingerprint_hash' => $fingerprint->hash,
                    'label' => $fingerprint->label,
                    'user_agent' => $fingerprint->userAgent,
                    'last_ip' => $ip,
                    'last_city' => $city,
                    'last_country_code' => $location?->countryCode,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);

                return ['device' => $device, 'new' => true];
            } catch (UniqueConstraintViolationException) {
                // A concurrent login from the same new machine won the insert;
                // fall through and update the winner's row like any known device.
                $existing = Device::query()
                    ->where('user_id', $user->identifier())
                    ->where('fingerprint_hash', $fingerprint->hash)
                    ->firstOrFail();
            }
        }

        $existing->fill([
            // The label tracks the CURRENT parse: a browser change on the same
            // machine-ish fingerprint keeps history honest via user_agent.
            'label' => $fingerprint->label,
            'user_agent' => $fingerprint->userAgent,
            'last_ip' => $ip,
            'last_city' => $city,
            'last_country_code' => $location?->countryCode,
            'last_seen_at' => $now,
        ]);
        $existing->save();

        return ['device' => $existing, 'new' => false];
    }
}
