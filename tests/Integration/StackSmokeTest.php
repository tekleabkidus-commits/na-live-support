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
|   6. every port docker/compose.yaml publishes has a REAL host listener
|      (Doc 18 FR-01 / Doc 19 FR-09 — S1 opening housekeeping)
|   7. the host-published Postgres/Redis ports reach the stack's OWN
|      containers, not a host impostor answering on the same port
|
*/

use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Symfony\Component\Yaml\Yaml;

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

/**
 * The HOST's address, as seen from inside this container.
 *
 * This is the whole trick behind the published-port assertions below. The
 * suite runs INSIDE the app container and therefore cannot read the host's
 * `ss -ltn`. But it can dial the host across the bridge — and the bridge
 * gateway address is exactly what a working `docker-proxy` binds and a
 * loopback-only host daemon does NOT. So "can I reach the host on this port"
 * is a faithful proxy for "is there a real host listener on this port".
 *
 * Read from /proc/net/route (little-endian hex) because the slim app image
 * ships no `ip`/`route` binary.
 */
function hostGateway(): string
{
    foreach (file('/proc/net/route') ?: [] as $i => $line) {
        if ($i === 0) {
            continue;
        }
        $fields = preg_split('/\s+/', trim($line)) ?: [];
        if (($fields[1] ?? '') === '00000000' && isset($fields[2])) {
            return long2ip((int) hexdec(implode('', array_reverse(str_split($fields[2], 2)))));
        }
    }

    throw new RuntimeException('no default route in /proc/net/route — cannot locate the host from inside the container');
}

/**
 * Every host port docker/compose.yaml publishes, as "service" => [ports].
 *
 * Parsed from the compose file itself, not hardcoded: a service that gains a
 * published port next month is covered without anyone remembering to edit
 * this test.
 *
 * @return array<string, list<int>>
 */
function publishedHostPorts(): array
{
    /** @var mixed $compose */
    $compose = Yaml::parseFile(base_path('docker/compose.yaml'));
    $services = (is_array($compose) && is_array($compose['services'] ?? null)) ? $compose['services'] : [];

    $out = [];
    foreach ($services as $service => $definition) {
        if (! is_array($definition) || ! is_array($definition['ports'] ?? null)) {
            continue;
        }
        foreach ($definition['ports'] as $mapping) {
            // "80:80" and "6380:6379" — the host port is the part before the colon.
            if (is_string($mapping) && str_contains($mapping, ':')) {
                $out[(string) $service][] = (int) strtok($mapping, ':');
            }
        }
    }
    ksort($out);

    return $out;
}

test('every port compose.yaml publishes has a real listener on the host', function () {
    // WHY THIS EXISTS (docs/BUILD-STATUS.md, cleared redis blocker): for ~53
    // minutes redis-volatile reported `0.0.0.0:6379->6379` and `healthy` while
    // *:6379 was unbound and a native host daemon served every host
    // connection. A container healthcheck runs INSIDE the container and is
    // structurally blind to a failed host-side docker-proxy bind. This test is
    // the eye the healthcheck does not have.
    $gateway = hostGateway();
    $published = publishedHostPorts();

    expect($published)->not->toBeEmpty('parsed no published ports out of docker/compose.yaml — the parser broke, not the stack');

    $unbound = [];
    foreach ($published as $service => $ports) {
        foreach ($ports as $port) {
            if (! reachable($gateway, $port)) {
                $unbound[] = sprintf('%s (host port %d)', $service, $port);
            }
        }
    }

    expect($unbound)->toBe([], sprintf(
        'published port(s) with NO host listener: %s. The container may still be healthy — '
        .'the healthcheck cannot see this. A docker-proxy that failed to bind at start does not retry: '
        .'`docker compose -f docker/compose.yaml up -d --force-recreate <service>`.',
        implode(', ', $unbound),
    ));
});

test('host-published database ports reach the stack containers, not a host impostor', function () {
    // Port-open (above) proves SOMETHING listens. It cannot prove it is OURS:
    // a host daemon bound to 0.0.0.0 answers just as readily. These are the
    // three ports a developer machine actually collides on, so each is pinned
    // to a server-identity value that differs between any two instances.
    $gateway = hostGateway();

    // Redis: run_id is regenerated per server start and is unique per instance.
    foreach ([['REDIS_VOLATILE', 'redis-volatile', 6379], ['REDIS_DURABLE', 'redis-durable', 6380]] as [$prefix, $dnsHost, $hostPort]) {
        $viaContainerDns = redisClient($prefix, $dnsHost);

        $viaHostPort = new Redis;
        $viaHostPort->connect($gateway, $hostPort, 2);

        expect($viaHostPort->info('server')['run_id'])->toBe(
            $viaContainerDns->info('server')['run_id'],
            sprintf(
                'host port %d is served by a DIFFERENT redis than the %s container — a host daemon is '
                .'shadowing the published port. This is the exact failure that hid for 53 minutes in S0.',
                $hostPort,
                $dnsHost,
            ),
        );
    }

    // Postgres: system_identifier is generated at initdb and is unique per cluster.
    $viaContainerDns = pgConnection(getenv('DB_USERNAME') ?: 'app', getenv('DB_PASSWORD') ?: 'app_local_password')
        ->query('SELECT system_identifier FROM pg_control_system()')->fetchColumn();

    $viaHostPort = (new PDO(
        sprintf('pgsql:host=%s;port=5432;dbname=na_live_support', $gateway),
        getenv('DB_USERNAME') ?: 'app',
        getenv('DB_PASSWORD') ?: 'app_local_password',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
    ))->query('SELECT system_identifier FROM pg_control_system()')->fetchColumn();

    expect($viaHostPort)->toBe(
        $viaContainerDns,
        'host port 5432 is served by a DIFFERENT postgres cluster than the postgres container',
    );
});

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
