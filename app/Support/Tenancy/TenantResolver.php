<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Foundation\Tenancy\TenantState;
use App\Support\Cache\TenantCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Turns a server-controlled credential into a tenant (Doc 01 FR-03, FR-04, SEC-06,
 * NFR-02).
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * FR-04 IS ENFORCED BY SHAPE, NOT BY VALIDATION.
 *
 * "Tenant id SHALL NEVER be accepted from client-supplied input (body, query,
 * header) on any endpoint." The usual way to implement that is to validate — strip
 * a `tenant_id` field, reject a header, add a test. This class does something
 * stronger: it never reads request input at all. `fromRequest()` takes the
 * PRESENTED CREDENTIAL from the route's own configuration and nothing else. There
 * is no code path from `$request->input()` to a tenant id, so there is nothing for
 * a fuzzer to find — which is why AC-02 passes 500 forged requests without a
 * single validation rule existing anywhere.
 *
 * A forged `tenant_id` in a body, query or header is not rejected here. It is
 * simply never looked at.
 * ────────────────────────────────────────────────────────────────────────────────
 *
 * SEC-06: a credential that does not resolve produces 404, never 403, and
 * increments a security metric. 403 would confirm that the tenant exists, which
 * turns any endpoint into a tenant-enumeration oracle.
 *
 * NFR-02 (≤1 ms p99): the key→tenant mapping is cached in Redis. Tenant STATE is
 * deliberately NOT cached — see resolveKey().
 */
final class TenantResolver
{
    public function __construct(private readonly TenantCache $cache) {}

    /**
     * How long a key→tenant mapping stays cached.
     *
     * Long, because it is nearly immutable: a key belongs to one tenant for its
     * whole life. Revocation does not rely on this expiring — the state and
     * revocation check below reads the database — so the TTL is a memory bound, not
     * a security window.
     */
    private const KEY_CACHE_TTL = 3600;

    /**
     * Resolve from a presented key (FR-03b–e).
     *
     * @param  string  $presented  the full credential as presented, "prefix.secret"
     */
    public function resolveKey(ResolutionSource $source, string $presented): ResolvedTenant
    {
        $kind = $source->keyKind();

        if ($kind === null) {
            throw new RuntimeException(sprintf(
                '%s is not a key-bearing resolution path.',
                $source->value,
            ));
        }

        [$prefix, $secret] = $this->split($presented, $source);
        $hash = hash('sha256', $secret);

        // The cache holds only the key→tenant mapping. It does NOT hold tenant
        // state or revocation, and that asymmetry is the point: caching those would
        // create a window in which a revoked key still works and a suspended tenant
        // still sends. So the cached path still costs one indexed primary-key read
        // to answer "is this tenant still allowed to do this", which is the read
        // that must never be stale.
        // Through TenantCache's preContext surface — the ONE sanctioned exception to
        // t:{tenant}: prefixing, because this cache exists to discover the tenant (T-09).
        $cacheKey = sprintf('t:keymap:%s:%s:%s', $kind, $prefix, substr($hash, 0, 16));

        $tenantId = $this->cache->preContextGet($cacheKey);

        if ($tenantId === null) {
            $row = $this->lookupKey($kind, $prefix, $hash);

            if ($row === null) {
                $this->recordFailedResolution($source, $prefix);

                throw TenantNotResolved::forSource($source);
            }

            $tenantId = $row->tenant_id;
            $this->cache->preContextPut($cacheKey, $tenantId, self::KEY_CACHE_TTL);

            if ($row->revoked) {
                $this->recordFailedResolution($source, $prefix);

                throw TenantNotResolved::forSource($source);
            }

            return new ResolvedTenant(
                tenantId: $tenantId,
                state: TenantState::from($row->tenant_state),
                source: $source,
                keyId: $row->key_id,
            );
        }

        // Cache hit: re-verify revocation and read live state.
        $row = $this->lookupKey($kind, $prefix, $hash);

        if ($row === null || $row->revoked) {
            $this->cache->preContextForget($cacheKey);
            $this->recordFailedResolution($source, $prefix);

            throw TenantNotResolved::forSource($source);
        }

        return new ResolvedTenant(
            tenantId: $row->tenant_id,
            state: TenantState::from($row->tenant_state),
            source: $source,
            keyId: $row->key_id,
        );
    }

    /**
     * Resolve from an authenticated staff user (FR-03a).
     *
     * The tenant comes from the USER RECORD, not from the request. A session or
     * Sanctum token identifies a user; the user belongs to a tenant. Doc 02 owns how
     * the user got authenticated.
     */
    public function resolveStaff(Request $request): ResolvedTenant
    {
        $user = $request->user();

        if ($user === null) {
            throw TenantNotResolved::forSource(ResolutionSource::StaffSession);
        }

        $tenantId = $user->getAttribute('tenant_id');

        if (! is_string($tenantId)) {
            throw TenantNotResolved::forSource(ResolutionSource::StaffSession);
        }

        return new ResolvedTenant(
            tenantId: $tenantId,
            state: $this->readState($tenantId, ResolutionSource::StaffSession),
            source: ResolutionSource::StaffSession,
        );
    }

    /**
     * Resolve from a tenant id that a trusted server-side context already holds
     * (FR-03f queued jobs, T-05's console and socket bindings).
     *
     * "Trusted" is doing real work in that sentence. A job payload was written by a
     * dispatcher that already had a bound context; an Artisan `--tenant` came from
     * an operator at a shell; a Reverb channel name was authorised against an
     * authenticated user. None is client-supplied input to a request.
     */
    public function resolveTrustedTenantId(string $tenantId, ResolutionSource $source): ResolvedTenant
    {
        return new ResolvedTenant(
            tenantId: $tenantId,
            state: $this->readState($tenantId, $source),
            source: $source,
        );
    }

    /**
     * @return object{key_id: string, tenant_id: string, tenant_state: string, revoked: bool}|null
     */
    private function lookupKey(string $kind, string $prefix, string $hash): ?object
    {
        // The SECURITY DEFINER function is the app role's only door onto
        // tenant_keys — it holds no SELECT privilege on the table's hash column and
        // no way to read the table before a tenant is bound. See the tenant_keys
        // migration for why this indirection exists.
        /** @var list<object{key_id: string, tenant_id: string, tenant_state: string, revoked: bool}> $rows */
        $rows = DB::select(
            'SELECT key_id, tenant_id, tenant_state, revoked FROM resolve_tenant_key(?, ?, ?)',
            [$kind, $prefix, $hash],
        );

        return $rows[0] ?? null;
    }

    private function readState(string $tenantId, ResolutionSource $source): TenantState
    {
        // Through the SECURITY DEFINER function, NOT a direct SELECT.
        //
        // `tenants` is RLS-policed on `id` so that one tenant cannot observe another's
        // existence (SEC-06), and resolution runs before any context is bound — so a direct
        // read here fails with `invalid input syntax for type uuid: ""` when
        // `app.tenant_id` is empty. That is the RLS layer correctly refusing an unscoped
        // read; the fix is not to weaken it but to ask a narrow question through a function
        // that may answer it. Exactly the same shape as resolve_tenant_key().
        //
        // See the create_resolve_tenant_state_function migration: a direct read passed its
        // tests for a while only because fixtures had left the session variable populated.
        /** @var list<object{state: string|null}> $rows */
        $rows = DB::select('SELECT resolve_tenant_state(?::uuid) AS state', [$tenantId]);
        $state = $rows[0]->state ?? null;

        if (! is_string($state)) {
            $this->recordFailedResolution($source, $tenantId);

            throw TenantNotResolved::forSource($source);
        }

        return TenantState::from($state);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $presented, ResolutionSource $source): array
    {
        $parts = explode('.', $presented, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw TenantNotResolved::forSource($source);
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * SEC-06's security metric.
     *
     * Deliberately does not log the presented secret — only the non-secret prefix,
     * which is enough to identify which key was tried without writing a credential
     * into the log stream (C-13).
     */
    private function recordFailedResolution(ResolutionSource $source, string $identifier): void
    {
        Log::warning('tenant resolution failed', [
            'source' => $source->value,
            'identifier' => $identifier,
            'security_metric' => 'tenant.resolution_failed',
        ]);
    }
}
