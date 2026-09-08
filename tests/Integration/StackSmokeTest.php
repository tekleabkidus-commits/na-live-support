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
|   4. Horizon supervises all twelve queue families
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
    'mail', // ADR 0007 — Doc 02 FS-02's auth-email family
    'sms-reengagement', 'sms-broadcast', 'sms-dlr', // Doc 14 HORIZON FAMILY AMENDMENT 1 (S14 step 2b): the three new SMS supervisors beside `sms`
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

test('the running app server answers /up over TLS with a chain that validates', function () {
    [$host, $port] = stackEndpoint('App server', 'app', 443);

    // verify_peer TRUE, against the dev CA in the image trust store (Doc 02
    // SEC-06). A smoke test that passed `verify_peer => false` would report a
    // healthy stack while serving an expired, self-signed or wrong-host
    // certificate — which is most of what can actually go wrong with TLS.
    $ctx = stream_context_create([
        'http' => ['timeout' => 5],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents(sprintf('https://%s:%d/up', $host, $port), false, $ctx);
    $status = isset($http_response_header[0]) ? $http_response_header[0] : 'no response';

    expect($body)->not->toBeFalse('GET https:///up returned no body — TLS handshake or app failure')
        ->and($status)->toContain('200');
});

test('the app serves NOTHING over cleartext: :80 only redirects (SEC-06)', function () {
    [$host, $port] = stackEndpoint('App server cleartext', 'app', 80);

    $ctx = stream_context_create([
        'http' => ['timeout' => 5, 'follow_location' => 0, 'ignore_errors' => true],
    ]);
    $body = @file_get_contents(sprintf('http://%s:%d/up', $host, $port), false, $ctx);
    $headers = implode("\n", $http_response_header ?? []);

    // 308 preserves the method, so a POST of credentials is never silently
    // downgraded to a GET that drops its body somewhere in the middle.
    expect($headers)->toContain('308')
        ->and($headers)->toContain('Location: https://')
        // The health payload must NOT appear: a redirect that also serves the
        // body has still served the body over cleartext.
        ->and((string) $body)->not->toContain('Application up');
});

test('the app certificate covers every name the stack addresses it by', function () {
    [$host, $port] = stackEndpoint('App server', 'app', 443);

    $ctx = stream_context_create([
        'ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $client = @stream_socket_client(
        sprintf('ssl://%s:%d', $host, $port),
        $errno,
        $errstr,
        5,
        STREAM_CLIENT_CONNECT,
        $ctx,
    );
    expect($client)->not->toBeFalse("TLS handshake with the app failed: $errstr");

    $params = stream_context_get_params($client);
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    fclose($client);

    $san = $cert['extensions']['subjectAltName'] ?? '';

    // Container-to-container and host-side names both have to work, or half the
    // stack silently falls back to cleartext or fails at handshake time.
    expect($san)->toContain('DNS:localhost')
        ->and($san)->toContain('DNS:app')
        ->and($san)->toContain('DNS:reverb')
        // Not expired, and not about to be: a dev cert that quietly lapses looks
        // like a networking outage to whoever hits it next.
        ->and($cert['validTo_time_t'])->toBeGreaterThan(time() + 86400 * 30);
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

    // …and the set of RLS-bypassing roles is EXACTLY these two, no more.
    //
    // Session 0 asserted `['platform_admin']`. S1 added `key_resolver` and the
    // justification has to live next to the assertion, because a new BYPASSRLS role
    // appearing without one is a Sev-1:
    //
    //   platform_admin  LOGIN, password in the environment. Used by the platform
    //                   console and retention jobs, gated by PlatformConnection which
    //                   refuses to run while a tenant is bound and logs actor
    //                   identity (SEC-02).
    //   key_resolver    NOLOGIN, NO password, in no environment variable. Cannot be
    //                   connected as by anyone. Owns exactly one SECURITY DEFINER
    //                   function, resolve_tenant_key(), whose body reads a single row
    //                   matched on an exact key hash. It exists because FR-03
    //                   resolution must look up a credential BEFORE a tenant is
    //                   known, and FORCE ROW LEVEL SECURITY applies to owners — so a
    //                   migrator-owned function was filtered by the very value it
    //                   exists to discover.
    //
    // The two assertions below are deliberately separate: the second one is what
    // makes key_resolver's bypass acceptable, and it must fail if that role ever
    // gains the ability to log in.
    $bypassers = $pdo->query('SELECT rolname FROM pg_roles WHERE rolbypassrls AND NOT rolsuper ORDER BY rolname')->fetchAll(PDO::FETCH_COLUMN);
    expect($bypassers)->toBe(['key_resolver', 'platform_admin']);

    $row = $pdo->query("SELECT rolcanlogin FROM pg_roles WHERE rolname = 'key_resolver'")->fetch(PDO::FETCH_ASSOC);
    expect($row)->not->toBeFalse('role "key_resolver" is missing from pg_roles')
        ->and($row['rolcanlogin'])->toBeFalse(
            'SEV-1: key_resolver can log in. Its RLS bypass is only acceptable because it is reachable '
            .'solely by invoking one SECURITY DEFINER function, never by connecting as it.',
        );

    // The function must actually be owned by that role — otherwise the NOLOGIN role is
    // decoration and the bypass is sitting somewhere else.
    $owner = $pdo->query("
        SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'resolve_tenant_key'
    ")->fetchColumn();
    expect($owner)->toBe('key_resolver');
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

test('horizon supervises all twelve queue families', function () {
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

test('reverb accepts a websocket connection over WSS with a validated chain', function () {
    [$host, $port] = stackEndpoint('Reverb', 'reverb', 8080);

    $key = getenv('REVERB_APP_KEY') ?: 'local-key';

    // ssl:// with verify_peer, not fsockopen. Realtime carries the session
    // events T-04 uses to force-close revoked sessions (AC-04), so it is the
    // last transport that may be cleartext — and a plain TCP connect would
    // succeed against a Reverb serving no TLS at all, which is exactly the
    // regression this asserts against.
    $ctx = stream_context_create([
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host],
    ]);
    $socket = @stream_socket_client(
        sprintf('ssl://%s:%d', $host, $port),
        $errno,
        $errstr,
        5,
        STREAM_CLIENT_CONNECT,
        $ctx,
    );
    expect($socket)->not->toBeFalse("WSS connect to Reverb failed: $errstr");

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
