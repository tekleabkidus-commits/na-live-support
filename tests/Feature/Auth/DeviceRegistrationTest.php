<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

/**
 * FR-04 / T-03 / T-08 / FS-04 — the device upsert on the REAL login route.
 *
 * Everything here goes through POST /login: the fingerprint is computed from
 * the headers the request actually carried, the IP from REMOTE_ADDR (set via
 * withServerVariables — postJson's third argument is HEADERS, the trap S5
 * documented), and the city from the ADR 0006 stub, which is what the test
 * environment's GEOIP_DATABASE_PATH points at.
 */
const CHROME_WINDOWS_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const FIREFOX_LINUX_UA = 'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0';

function deviceUser(): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create([
        'email' => 'device@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $user];
}

function loginFrom(string $ip, string $userAgent): Illuminate\Testing\TestResponse
{
    // The guard keeps the previous login's user in process memory, and
    // guest:staff would 302 the next attempt. forgetUser (not logout) — logout
    // would cycle a remember token, which is a user SAVE with no tenant bound.
    $guard = auth('staff');
    if ($guard instanceof Illuminate\Auth\SessionGuard) {
        $guard->forgetUser();
    }

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/login', [
            'email' => 'device@fixture.example',
            'password' => 'the-correct-password',
        ], ['User-Agent' => $userAgent, 'Accept-Language' => 'en-ET']);
}

/** @return list<Device> */
function devicesOf(mixed $tenant, User $user): array
{
    return asTenant($tenant, fn (): array => Device::query()
        ->where('user_id', (string) $user->getKey())
        ->orderBy('first_seen_at')
        ->get()
        ->all());
}

it('records a device on first login: fingerprint, parsed label, IP, and the stub-resolved city (FR-04, T-03, T-08)', function (): void {
    [$tenant, $user] = deviceUser();

    loginFrom('192.0.2.7', CHROME_WINDOWS_UA)->assertOk();

    $devices = devicesOf($tenant, $user);
    expect($devices)->toHaveCount(1);

    $device = $devices[0];
    expect($device->fingerprint_hash)->toHaveLength(64)
        ->and($device->label)->toBe('Chrome · Windows')
        ->and($device->last_ip)->toBe('192.0.2.7')
        ->and($device->last_city)->toBe('Addis Ababa')
        ->and($device->last_country_code)->toBe('ET')
        ->and($device->first_seen_at?->equalTo($device->last_seen_at))->toBeTrue();
});

it('a repeat login from the same machine UPDATES the row instead of growing the table', function (): void {
    [$tenant, $user] = deviceUser();

    loginFrom('192.0.2.7', CHROME_WINDOWS_UA)->assertOk();
    loginFrom('198.51.100.9', CHROME_WINDOWS_UA)->assertOk();

    $devices = devicesOf($tenant, $user);
    expect($devices)->toHaveCount(1)
        ->and($devices[0]->last_ip)->toBe('198.51.100.9')
        ->and($devices[0]->last_city)->toBe('Dire Dawa');
});

it('a different browser is a different device (T-03: family is a fingerprint component)', function (): void {
    [$tenant, $user] = deviceUser();

    loginFrom('192.0.2.7', CHROME_WINDOWS_UA)->assertOk();
    loginFrom('192.0.2.7', FIREFOX_LINUX_UA)->assertOk();

    $devices = devicesOf($tenant, $user);
    expect($devices)->toHaveCount(2)
        ->and($devices[1]->label)->toBe('Firefox · Linux');
});

it('a browser UPGRADE is NOT a new device (family, not version, is hashed)', function (): void {
    [$tenant, $user] = deviceUser();

    loginFrom('192.0.2.7', CHROME_WINDOWS_UA)->assertOk();
    loginFrom('192.0.2.7', str_replace('Chrome/126.0.0.0', 'Chrome/127.0.0.0', CHROME_WINDOWS_UA))->assertOk();

    expect(devicesOf($tenant, $user))->toHaveCount(1);
});

it('an address outside the database stores IP only — the row-level half of FS-04', function (): void {
    [$tenant, $user] = deviceUser();

    // 192.0.3.1 is a real, routable-looking address the stub does not contain.
    loginFrom('192.0.3.1', CHROME_WINDOWS_UA)->assertOk();

    $devices = devicesOf($tenant, $user);
    expect($devices[0]->last_ip)->toBe('192.0.3.1')
        ->and($devices[0]->last_city)->toBeNull()
        ->and($devices[0]->last_country_code)->toBeNull();
});

it('NFR-04: a CORRUPT GeoIP database never blocks a login', function (): void {
    // The assertion that matters most in this file: GeoIP is an enrichment,
    // and the enrichment failing must cost the row its city — never the user
    // their login.
    [$tenant, $user] = deviceUser();

    $corrupt = sys_get_temp_dir().'/corrupt-login-'.uniqid().'.mmdb';
    file_put_contents($corrupt, str_repeat("\x42", 4096));
    config(['services.geoip.database' => $corrupt]);

    loginFrom('192.0.2.7', CHROME_WINDOWS_UA)->assertOk();

    $devices = devicesOf($tenant, $user);
    expect($devices)->toHaveCount(1)
        ->and($devices[0]->last_ip)->toBe('192.0.2.7')
        ->and($devices[0]->last_city)->toBeNull();
});
