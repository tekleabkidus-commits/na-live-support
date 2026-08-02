<?php

declare(strict_types=1);

use App\Support\GeoIp\GeoIpResolver;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;

/**
 * T-08 / FS-04 / ADR 0006 — the GeoIP stub and the resolver's fail-open posture.
 *
 * The stub's validity is asserted through the OFFICIAL MaxMind reader, not our
 * resolver: writer (ours) and reader (MaxMind's) come from different authors,
 * so if the reader returns the fixture records the file is a valid MMDB — a
 * green here cannot be a self-consistent misunderstanding of the format.
 */
function corruptMmdb(): string
{
    $path = sys_get_temp_dir().'/corrupt-'.uniqid().'.mmdb';
    // Real length, garbage content: long enough to pass any size sniff, no
    // MMDB metadata marker anywhere.
    file_put_contents($path, str_repeat("\x42", 4096));

    return $path;
}

it('positive control: the official MaxMind reader resolves the stub\'s fixture networks', function (): void {
    $reader = new Reader(base_path('fixtures/geoip/stub-city.mmdb'));

    $addis = $reader->get('192.0.2.7');
    expect($addis)->toBeArray()
        ->and($addis['city']['names']['en'] ?? null)->toBe('Addis Ababa')
        ->and($addis['city']['names']['am'] ?? null)->toBe('አዲስ አበባ')
        ->and($addis['country']['iso_code'] ?? null)->toBe('ET');

    $dire = $reader->get('198.51.100.200');
    expect($dire)->toBeArray()
        ->and($dire['city']['names']['en'] ?? null)->toBe('Dire Dawa');

    // The country-only network: resolves, but has no city to name.
    $countryOnly = $reader->get('203.0.113.1');
    expect($countryOnly)->toBeArray()
        ->and($countryOnly['country']['iso_code'] ?? null)->toBe('ET')
        ->and($countryOnly)->not->toHaveKey('city');

    // An address outside every fixture network resolves to nothing.
    expect($reader->get('192.0.3.1'))->toBeNull();
});

it('resolves a city with per-locale names and English fallback (§14)', function (): void {
    $location = app(GeoIpResolver::class)->locate('192.0.2.7');

    expect($location)->not->toBeNull()
        ->and($location?->cityFor('am'))->toBe('አዲስ አበባ')
        ->and($location?->cityFor('en'))->toBe('Addis Ababa')
        // A locale the data lacks falls back to English, not to nothing.
        ->and($location?->cityFor('om'))->toBe('Addis Ababa')
        ->and($location?->countryCode)->toBe('ET');
});

it('returns a country-only location for the city-less network', function (): void {
    $location = app(GeoIpResolver::class)->locate('203.0.113.1');

    expect($location)->not->toBeNull()
        ->and($location?->cityFor('en'))->toBeNull()
        ->and($location?->countryCode)->toBe('ET');
});

it('returns null for an address the database does not know', function (): void {
    expect(app(GeoIpResolver::class)->locate('192.0.3.1'))->toBeNull();
});

it('FS-04: a CORRUPT database degrades to null with the named metric, never an exception', function (): void {
    // Fresh resolver: the singleton may hold a healthy memoized reader from an
    // earlier test, and FS-04 is about opening a bad file.
    config(['services.geoip.database' => corruptMmdb()]);
    Log::spy();

    $location = (new GeoIpResolver)->locate('192.0.2.7');

    expect($location)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => ($context['metric'] ?? null) === 'auth.geoip.lookup_failed')
        ->atLeast()->once();
});

it('FS-04: a MISSING database file degrades the same way', function (): void {
    config(['services.geoip.database' => '/nonexistent/geoip/city.mmdb']);
    Log::spy();

    expect((new GeoIpResolver)->locate('192.0.2.7'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => ($context['metric'] ?? null) === 'auth.geoip.lookup_failed')
        ->atLeast()->once();
});

it('an UNCONFIGURED database is a quiet null — no metric spam from undeployed environments', function (): void {
    config(['services.geoip.database' => '']);
    Log::spy();

    expect((new GeoIpResolver)->locate('192.0.2.7'))->toBeNull();

    Log::shouldNotHaveReceived('warning');
});

it('heals without a restart when a corrupt file is replaced (the un-pinned reader)', function (): void {
    // Octane workers are long-lived: a resolver that memoized its FAILURE would
    // keep a healed database invisible until a deploy. Same instance, two
    // outcomes.
    $resolver = new GeoIpResolver;

    config(['services.geoip.database' => corruptMmdb()]);
    expect($resolver->locate('192.0.2.7'))->toBeNull();

    config(['services.geoip.database' => 'fixtures/geoip/stub-city.mmdb']);
    expect($resolver->locate('192.0.2.7')?->cityFor('en'))->toBe('Addis Ababa');
});
