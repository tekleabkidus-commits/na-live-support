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
| EVERY endpoint resolves via container DNS only, with no localhost fallback:
| a host service on the same published port (a native redis-server on 6379, an
| unrelated Postgres on 5432) would answer and be asserted against silently.
| See stackEndpoint().
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

/** True if host:port accepts a TCP connection within the timeout. */
function reachable(string $host, int $port): bool
{
    $s = @fsockopen($host, $port, $errno, $errstr, 2);
    if ($s === false) {
        return false;
    }
    fclose($s);

    return true;
}

/**
 * Resolve a stack endpoint from container DNS ONLY — never localhost.
 *
 * There is deliberately no host-port fallback anywhere in this suite. A
 * developer machine routinely runs its own services on the very ports the
 * stack publishes — this one has had a native redis-server on 6379 and an
 * unrelated `kiron-db` Postgres container — so a `localhost` fallback lets the
 * suite connect to the WRONG server and assert against it, silently, with the
 * stack up and healthy. That is precisely the failure this smoke test exists
 * to catch, so it must fail loudly instead of degrading. A fallback also hides
 * a broken container network behind a green run, which is worse than a red one.
 *
 * Run it inside the stack: `make test`.
 */
function stackEndpoint(string $label, string $host, int $port): array
{
    if (! reachable($host, $port)) {
        throw new RuntimeException(sprintf(
            '%s unreachable at %s:%d. This suite runs INSIDE the stack — use `make test`, not '
            .'pest from the host. (No localhost fallback exists: a host service on the same port '
            .'would answer and the assertions below would silently target the wrong server.)',
            $label,
            $host,
            $port,
        ));
    }

    return [$host, $port];
}

function pgConnection(string $user, string $password): PDO
{
    [$host, $port] = stackEndpoint(
        'Postgres',
        getenv('DB_HOST') ?: 'postgres',
        (int) (getenv('DB_PORT') ?: 5432),
    );

    return new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=na_live_support', $host, $port),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
    );
}

function redisEndpoint(string $envPrefix, string $defaultHost): array
{
    return stackEndpoint(
        $envPrefix.' Redis',
        getenv($envPrefix.'_HOST') ?: $defaultHost,
        (int) (getenv($envPrefix.'_PORT') ?: 6379),
    );
}

function redisClient(string $envPrefix, string $defaultHost): Redis
{
    [$host, $port] = redisEndpoint($envPrefix, $defaultHost);

    $client = new Redis;
    $client->connect($host, $port, 2);

    return $client;
}

test('the running app server answers /up', function () {
    [$host, $port] = stackEndpoint('App server', 'app', 80);

    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $body = @file_get_contents(sprintf('http://%s:%d/up', $host, $port), false, $ctx);
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
    // only — see stackEndpoint(): a localhost fallback could reach a host
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
    [$host, $port] = stackEndpoint('Reverb', 'reverb', 8080);

    $key = getenv('REVERB_APP_KEY') ?: 'local-key';
    $socket = fsockopen($host, $port, $errno, $errstr, 5);
    expect($socket)->not->toBeFalse("TCP connect to Reverb failed: $errstr");

    $handshake = "GET /app/$key HTTP/1.1\r\n"
        ."Host: {$host}:{$port}\r\n"
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
