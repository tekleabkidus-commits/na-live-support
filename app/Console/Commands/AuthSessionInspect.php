<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Auth\Sessions\TenantHint;
use App\Support\Auth\Sessions\TenantPrefixedSessionHandler;
use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Cookie\CookieValuePrefix;
use RuntimeException;

/**
 * Test-only inspection of session storage and the tenant hint.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS. Two Doc 02 properties defeated three attempts to prove them
 * from the client side, for reasons that are structural rather than incidental:
 *
 *   SEC-04 (fixation). Laravel re-encrypts the session cookie with a fresh IV on
 *   every response, so cookie VALUES differ between two responses even when the
 *   session id is identical. No client-side comparison can see regeneration.
 *   The observable fact lives in Redis: the OLD key is gone, a NEW one exists.
 *
 *   Q7 (VerifyTenantHint's own comparison). Proving the middleware — as opposed
 *   to the signature check that precedes it — needs a VALIDLY SIGNED hint naming
 *   a DIFFERENT tenant. Only something holding the app key can mint that, and a
 *   browser must not.
 *
 * So the browser suite asks this command for the two things it cannot compute:
 * whether a given session key exists, and a correctly-signed hint for an
 * arbitrary tenant.
 *
 * SAFETY. It refuses to run outside local/testing/ci, and it mints a hint only
 * for a tenant id the caller already supplies — it grants nothing that holding
 * the app key would not already grant, and the app key is what an attacker would
 * need anyway. It reads session KEYS, never session CONTENTS.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AuthSessionInspect extends Command
{
    protected $signature = 'auth:session-inspect
        {action : one of "key-exists", "sign-hint", "keys-for-tenant", "decrypt-cookie", "clear-tenant"}
        {--tenant= : tenant id (omit for the unprefixed guest keyspace)}
        {--session= : session id}
        {--cookie= : an encrypted session cookie value}';

    protected $description = 'Test-only: inspect session storage / mint a signed tenant hint (Doc 02 SEC-04, Q7)';

    private function withoutPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix');
        $prefix = is_string($prefix) ? $prefix : '';

        return $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
    }

    public function handle(RedisFactory $redis, TenantHint $hint): int
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            throw new RuntimeException(
                'auth:session-inspect is a test affordance and must never run outside local/testing/ci.'
            );
        }

        $action = (string) $this->argument('action');
        $tenant = (string) $this->option('tenant');
        $session = (string) $this->option('session');
        $connection = config('session.connection');
        $store = $redis->connection(is_string($connection) && $connection !== '' ? $connection : 'default');

        switch ($action) {
            case 'key-exists':
                // The SEC-04 observable: does this exact session key still exist?
                // An empty --tenant means the unprefixed GUEST keyspace, which is
                // where a pre-login session lives — and where the id an attacker
                // would have planted must no longer be after authentication.
                $key = $tenant === ''
                    ? sprintf('guest:sess:%s', $session)
                    : TenantPrefixedSessionHandler::keyFor($tenant, $session);

                $this->line((string) (int) (bool) $store->exists($key));

                return self::SUCCESS;

            case 'decrypt-cookie':
                // Laravel encrypts the session cookie with a fresh IV per
                // response, so its VALUE tells a client nothing about the id
                // inside. Three attempts to prove SEC-04 from the client side
                // failed on exactly that. Decrypting here — with the app key the
                // application already holds — is what turns the cookie back into
                // the session id the storage assertions need.
                // Two unwrappings, both easy to miss and both fatal without:
                //   1. the value arrives URL-ENCODED from the cookie jar;
                //   2. Laravel prefixes every encrypted cookie payload with a
                //      CookieValuePrefix (a keyed hash of the cookie NAME) to
                //      stop a value being replayed under a different name.
                // Skipping either produces "The payload is invalid", which reads
                // like a wrong key and is not.
                $raw = urldecode((string) $this->option('cookie'));
                $decrypted = decrypt($raw, false);
                $value = is_string($decrypted) ? CookieValuePrefix::remove($decrypted) : '';

                $this->line($value);

                return self::SUCCESS;

            case 'keys-for-tenant':
                // Used by the cap/eviction proofs: how many sessions this tenant
                // currently holds, by storage key rather than by application
                // bookkeeping that could disagree with it.
                /** @var list<string> $keys */
                $keys = $store->keys(sprintf('t:%s:sess:*', $tenant));
                $this->line((string) count($keys));

                return self::SUCCESS;

            case 'clear-tenant':
                // TEST ISOLATION, and it earned its place. The FR-10 cap proof
                // logs in six times and asserts which session was evicted — but
                // sessions accumulate across runs, so the fixture user already
                // had dozens indexed. The eviction count was then large enough
                // that the oldest disappeared no matter which end the code
                // evicted from, and the test passed with the eviction ORDER
                // deliberately reversed. A test that cannot fail is not a test.
                $deleted = 0;
                foreach ([sprintf('t:%s:sess:*', $tenant), sprintf('t:%s:user-sessions:*', $tenant)] as $pattern) {
                    /** @var list<string> $keys */
                    $keys = $store->keys($pattern);
                    foreach ($keys as $key) {
                        // `keys()` returns FULLY-PREFIXED names while every
                        // command re-applies the prefix — strip it or the delete
                        // silently misses everything.
                        $store->del($this->withoutPrefix($key));
                        $deleted++;
                    }
                }
                $this->line((string) $deleted);

                return self::SUCCESS;

            case 'sign-hint':
                $this->line($hint->sign($tenant));

                return self::SUCCESS;

            default:
                $this->error(sprintf('Unknown action "%s".', $action));

                return self::FAILURE;
        }
    }
}
