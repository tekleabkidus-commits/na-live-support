<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Session 0 Part G — proof-of-life smoke test.
|------------------------------------------------------------------------------
|
| Runs against the REAL Docker stack (`make up`), from inside the app
| container or from the host (published ports). It does not skip: if the
| stack is down, it fails loudly — parity (Doc 01 SEC-01) means the stack IS
| the local environment.
|
| Asserts:
|   1. the running app answers /up (the actual Octane/FrankenPHP server)
|   2. Postgres is reachable; role physics (per owner's correction 3):
|      - `app` has rolbypassrls = false
|      - `platform_admin` exists, is distinct, and is the ONLY
|        non-superuser role with rolbypassrls = true
|      - the app's password cannot authenticate as platform_admin
|   3. both Redis instances respond and are DISTINCT servers with the
|      correct persistence/eviction personalities
|   4. Horizon supervises all eleven queue families
|   5. Reverb accepts a WebSocket connection (HTTP 101)
|
*/

use Laravel\Horizon\Contracts\MasterSupervisorRepository;

const QUEUE_FAMILIES = [
    'telegram-in', 'telegram-out', 'webchat-in', 'webchat-out', 'media',
    'ai', 'sms', 'automation', 'reports', 'outbox-relay', 'audit',
];

/** First host:port that accepts a TCP connection — container DNS or localhost. */
function reachable(array $candidates): ?array
{
    foreach ($candidates as [$host, $port]) {
        $s = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($s !== false) {
            fclose($s);

            return [$host, $port];
        }
    }

    return null;
}

function pgConnection(string $user, string $password): PDO
{
    $target = reachable([[getenv('DB_HOST') ?: 'postgres', 5432], ['localhost', 5432]]);
    if ($target === null) {
        throw new RuntimeException('Postgres unreachable — is the stack up? Run `make up` first.');
    }

    return new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=na_live_support', $target[0], $target[1]),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
    );
}

function redisClient(string $envPrefix, string $fallbackHost, int $fallbackPort): Redis
{
    $candidates = [
        [getenv($envPrefix.'_HOST') ?: $fallbackHost, (int) (getenv($envPrefix.'_PORT') ?: 6379)],
        ['localhost', $fallbackPort],
    ];
    $target = reachable($candidates);
    if ($target === null) {
        throw new RuntimeException($envPrefix.' Redis unreachable — is the stack up? Run `make up` first.');
    }
    $client = new Redis();
    $client->connect($target[0], $target[1], 2);

    return $client;
}

test('the running app server answers /up', function () {
    $target = reachable([['app', 80], ['localhost', 80]]);
    expect($target)->not->toBeNull('app server unreachable on app:80 / localhost:80 — run `make up`');

    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $body = @file_get_contents(sprintf('http://%s:%d/up', $target[0], $target[1]), false, $ctx);
    $status = isset($http_response_header[0]) ? $http_response_header[0] : 'no response';

    expect($body)->not->toBeFalse('GET /up returned no body')
        ->and($status)->toContain('200');
});

test('postgres role physics: app cannot bypass RLS, platform_admin is the only bypasser', function () {
    $pdo = pgConnection(getenv('DB_USERNAME') ?: 'app', getenv('DB_PASSWORD') ?: 'app_local_password');

    // The connected application role does not bypass RLS.
    $row = $pdo->query("SELECT rolname, rolbypassrls, rolsuper FROM pg_roles WHERE rolname = 'app'")->fetch(PDO::FETCH_ASSOC);
    expect($row)->not->toBeFalse('role "app" is missing from pg_roles')
        ->and($row['rolbypassrls'])->toBeFalse('SEV-1: the app role can bypass RLS')
        ->and($row['rolsuper'])->toBeFalse('SEV-1: the app role is a superuser');

    // platform_admin exists, is distinct from app, and bypasses RLS.
    $row = $pdo->query("SELECT rolname, rolbypassrls FROM pg_roles WHERE rolname = 'platform_admin'")->fetch(PDO::FETCH_ASSOC);
    expect($row)->not->toBeFalse('role "platform_admin" is missing from pg_roles')
        ->and($row['rolbypassrls'])->toBeTrue('platform_admin must bypass RLS for console/retention work');

    // …and it is the ONLY non-superuser role that may bypass RLS.
    $bypassers = $pdo->query('SELECT rolname FROM pg_roles WHERE rolbypassrls AND NOT rolsuper')->fetchAll(PDO::FETCH_COLUMN);
    expect($bypassers)->toBe(['platform_admin'], 'unexpected RLS-bypassing role(s): '.implode(', ', $bypassers));
});

test('app credentials cannot authenticate as platform_admin', function () {
    $appPassword = getenv('DB_PASSWORD') ?: 'app_local_password';

    expect(fn () => pgConnection('platform_admin', $appPassword))
        ->toThrow(PDOException::class);
});

test('both redis instances respond and are distinct servers with opposite personalities', function () {
    $volatile = redisClient('REDIS_VOLATILE', 'redis-volatile', 6379);
    $durable = redisClient('REDIS_DURABLE', 'redis-durable', 6380);

    expect($volatile->ping())->toBeTrue()
        ->and($durable->ping())->toBeTrue();

    // Distinct servers: different run_ids, and a marker key set on one must
    // not appear on the other.
    $volatileInfo = $volatile->info('server');
    $durableInfo = $durable->info('server');
    expect($volatileInfo['run_id'])->not->toBe($durableInfo['run_id'], 'volatile and durable point at the SAME Redis server');

    $marker = 'smoke:distinct:'.getmypid();
    $volatile->set($marker, '1', ['ex' => 30]);
    expect($durable->exists($marker))->toBe(0, 'marker key crossed instances — they are the same server');
    $volatile->del($marker);

    // Opposite personalities (the reason the split exists):
    expect($volatile->config('GET', 'maxmemory-policy')['maxmemory-policy'])->toBe('allkeys-lru')
        ->and($volatile->config('GET', 'appendonly')['appendonly'])->toBe('no')
        ->and($durable->config('GET', 'maxmemory-policy')['maxmemory-policy'])->toBe('noeviction')
        ->and($durable->config('GET', 'appendonly')['appendonly'])->toBe('yes');
});

test('horizon supervises all eleven queue families', function () {
    // Point Laravel's redis config at whichever durable endpoint is
    // reachable from here (container DNS or host-published port).
    $target = reachable([[getenv('REDIS_DURABLE_HOST') ?: 'redis-durable', 6379], ['localhost', 6380]]);
    expect($target)->not->toBeNull('durable Redis unreachable — run `make up`');
    config([
        'database.redis.durable.host' => $target[0],
        'database.redis.durable.port' => $target[1],
    ]);

    $masters = app(MasterSupervisorRepository::class)->all();
    expect($masters)->not->toBeEmpty('no Horizon master is running — is the worker container up?');

    $supervisorNames = [];
    foreach ($masters as $master) {
        foreach ($master->supervisors ?? [] as $supervisor) {
            $supervisorNames[] = (string) $supervisor;
        }
    }
    $joined = implode(' ', $supervisorNames);
    foreach (QUEUE_FAMILIES as $family) {
        expect($joined)->toContain($family, sprintf(
            'queue family "%s" has no Horizon supervisor (found: %s)',
            $family,
            $joined === '' ? 'none' : $joined,
        ));
    }
});

test('reverb accepts a websocket connection', function () {
    $target = reachable([['reverb', 8080], ['localhost', 8080]]);
    expect($target)->not->toBeNull('Reverb unreachable on reverb:8080 / localhost:8080 — run `make up`');

    $key = getenv('REVERB_APP_KEY') ?: 'local-key';
    $socket = fsockopen($target[0], $target[1], $errno, $errstr, 5);
    expect($socket)->not->toBeFalse("TCP connect to Reverb failed: $errstr");

    $handshake = "GET /app/$key HTTP/1.1\r\n"
        ."Host: {$target[0]}:{$target[1]}\r\n"
        ."Upgrade: websocket\r\n"
        ."Connection: Upgrade\r\n"
        .'Sec-WebSocket-Key: '.base64_encode(random_bytes(16))."\r\n"
        ."Sec-WebSocket-Version: 13\r\n\r\n";
    fwrite($socket, $handshake);
    stream_set_timeout($socket, 5);
    $response = (string) fread($socket, 1024);
    fclose($socket);

    expect($response)->toContain('101', "Reverb did not upgrade the connection. First bytes: ".substr($response, 0, 120));
});
