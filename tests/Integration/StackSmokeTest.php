<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Session 0 Part G — proof-of-life smoke test.
|------------------------------------------------------------------------------
|
| Runs against the REAL Docker stack, from INSIDE the app container (`make
| test`). It does not skip: if the stack is down, it fails loudly — parity
| (Doc 01 SEC-01) means the stack IS the local environment.
|
| The Redis checks resolve container DNS only, with no localhost fallback: a
| native redis-server on the host shadows the published 6379 and would be
| asserted against silently. See redisEndpoint().
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

/**
 * Resolve a Redis endpoint from container DNS ONLY — never localhost.
 *
 * There is deliberately no host-port fallback here. A native redis-server on
 * the developer's machine binds 127.0.0.1:6379 and shadows the container's
 * published port, so a `localhost` fallback lets this suite connect to the
 * WRONG server and assert against it — silently, with the stack up and healthy.
 * That is precisely the failure this smoke test exists to catch, so it must
 * fail loudly instead of degrading. Run it inside the stack: `make test`.
 */
function redisEndpoint(string $envPrefix, string $defaultHost): array
{
    $host = getenv($envPrefix.'_HOST') ?: $defaultHost;
    $port = (int) (getenv($envPrefix.'_PORT') ?: 6379);

    if (reachable([[$host, $port]]) === null) {
        throw new RuntimeException(sprintf(
            '%s Redis unreachable at %s:%d. This suite runs INSIDE the stack — use `make test`, '
            .'not pest from the host. (No localhost fallback exists: a host Redis would answer '
            .'and the assertions below would silently target the wrong server.)',
            $envPrefix,
            $host,
            $port,
        ));
    }

    return [$host, $port];
}

function redisClient(string $envPrefix, string $defaultHost): Redis
{
    [$host, $port] = redisEndpoint($envPrefix, $defaultHost);

    $client = new Redis;
    $client->connect($host, $port, 2);

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
    expect($bypassers)->toBe(['platform_admin']); // any other rolbypassrls role here is a Sev-1
});

test('app credentials cannot authenticate as platform_admin', function () {
    $appPassword = getenv('DB_PASSWORD') ?: 'app_local_password';

    expect(fn () => pgConnection('platform_admin', $appPassword))
        ->toThrow(PDOException::class);
});

test('both redis instances respond and are distinct servers with opposite personalities', function () {
    $volatile = redisClient('REDIS_VOLATILE', 'redis-volatile');
    $durable = redisClient('REDIS_DURABLE', 'redis-durable');

    expect($volatile->ping())->toBeTrue()
        ->and($durable->ping())->toBeTrue();

    // Distinct servers: different run_ids, and a marker key set on one must
    // not appear on the other.
    $volatileInfo = $volatile->info('server');
    $durableInfo = $durable->info('server');
    expect($volatileInfo['run_id'])->not->toBe($durableInfo['run_id']); // same run_id = same server = merged instances

    $marker = 'smoke:distinct:'.getmypid();
    $volatile->set($marker, '1', ['ex' => 30]);
    expect($durable->exists($marker))->toBe(0); // marker crossing instances = same server
    $volatile->del($marker);

    // Opposite personalities (the reason the split exists):
    expect($volatile->config('GET', 'maxmemory-policy')['maxmemory-policy'])->toBe('allkeys-lru')
        ->and($volatile->config('GET', 'appendonly')['appendonly'])->toBe('no')
        ->and($durable->config('GET', 'maxmemory-policy')['maxmemory-policy'])->toBe('noeviction')
        ->and($durable->config('GET', 'appendonly')['appendonly'])->toBe('yes');
});

test('horizon supervises all eleven queue families', function () {
    // Point Laravel's redis config at the durable endpoint. Container DNS
    // only — see redisEndpoint(): a localhost fallback could reach a host
    // Redis and read someone else's queue state.
    [$host, $port] = redisEndpoint('REDIS_DURABLE', 'redis-durable');
    config([
        'database.redis.durable.host' => $host,
        'database.redis.durable.port' => $port,
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
        // NOTE: toContain() is variadic (extra args are MORE needles, not a
        // message) — caught in Session 0 when the message-arg made this fail.
        expect($joined)->toContain($family);
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

    expect($response)->toContain('101 Switching Protocols');
});
