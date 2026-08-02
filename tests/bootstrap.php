<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Test bootstrap — environment overrides that must WIN (owner ruling D-4).
|------------------------------------------------------------------------------
|
| WHY THIS FILE EXISTS RATHER THAN <env> ENTRIES IN phpunit.xml.
|
| The suite runs inside the app container, which receives DB_DATABASE,
| CACHE_STORE and friends from `env_file: ../.env`. Those arrive in the process
| environment, and PHP publishes the process environment into $_SERVER. Laravel's
| env repository reads $_SERVER BEFORE $_ENV, so a phpunit `<env>` entry — even
| with force="true", which sets $_ENV and putenv — is shadowed by the container's
| value and silently ignored.
|
| The consequence was not subtle: the suite pointed at `na_live_support`, the
| DEVELOPMENT database, and tests/TestCase.php's `_test` suffix guard is the only
| reason `migrate:fresh` did not drop every table in it on the first run. That
| guard earned its place immediately; this file removes the need for it to fire.
|
| Setting all three of putenv/$_ENV/$_SERVER is deliberate. Any single one of them
| can be the source Laravel happens to read, and this file's whole job is to be
| unambiguous.
|
*/

require __DIR__.'/../vendor/autoload.php';

$overrides = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',

    // Postgres, not SQLite: SQLite has no RLS, no current_setting, no partitions
    // and no column-level grants, so it cannot run one assertion that matters in
    // this module (Doc 01 SEC-01).
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'na_live_support_test',
    'DB_URL' => '',

    // Real Redis: FS-01's DB fallback and NFR-03's ≤2 s propagation cannot be
    // tested against an in-process array driver. The prefix keeps the suite from
    // evicting the dev stack's cache.
    'CACHE_STORE' => 'redis',
    'REDIS_PREFIX' => 'na-test-',

    // ADR 0006: the synthetic GeoIP stub (RFC 5737 ranges only). Relative, so
    // GeoIpResolver anchors it at base_path(); the FS-04 tests override the
    // config at runtime to point at corrupt/missing files.
    'GEOIP_DATABASE_PATH' => 'fixtures/geoip/stub-city.mmdb',

    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];

foreach ($overrides as $key => $value) {
    putenv(sprintf('%s=%s', $key, $value));
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
