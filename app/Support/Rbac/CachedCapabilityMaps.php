<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\User;
use App\Support\Cache\TenantCache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The capability cache (Doc 03 T-02, FR-07, NFR-01/NFR-02): per-user maps in
 * Redis under `t:{tenant}:cap:{user}` (C-07's prefix, ruling R-11.2 reading
 * T-02's literal `cap:{tenant}:{user}` as descriptive), TTL 10 s, explicitly
 * forgotten by CapabilityCacheInvalidator on every rbac mutation.
 *
 * Staleness bounds, on the record (the plan's combined-bound statement):
 *  - normal path: mutation → explicit forget on the SHARED Redis → next check
 *    anywhere in the cluster rebuilds; the invalidator also busts Doc 02's
 *    per-token scope cache, so BOTH surfaces converge ≤ 10 s (their EC-06 TTL
 *    is the token surface's own bound);
 *  - FS-02 (invalidation lost): this TTL bounds the session surface at 10 s;
 *    the token surface at 10 s (cap) + 10 s (token cache) = 20 s worst case —
 *    the documented maximum exposure window.
 *
 * FS-01: Redis down → every call resolves per request from the database.
 * Latency degrades (within NFR ×10), the named metric fires, and VERDICTS DO
 * NOT CHANGE — falsified in CapabilityCacheTest by killing the store and
 * asserting byte-identical verdicts.
 *
 * SEC-05: the cache holds Verdict/CapabilityMap value objects only — never
 * secrets — and every entry is written by THIS class from a server-side
 * rebuild; no client input reaches it.
 *
 * R-04 (§21): customers.rg_flag.view, customers.rg_flag.set, audit.view and
 * audit.export bypass the cache entirely on verdictFor() — revoking the
 * platform's most sensitive reads waits for nobody's TTL.
 */
final class CachedCapabilityMaps implements CapabilityMapProvider
{
    public const TTL_SECONDS = 10;

    /** @var list<string> */
    public const CACHE_BYPASS_KEYS = [
        'customers.rg_flag.view',
        'customers.rg_flag.set',
        'audit.view',
        'audit.export',
    ];

    public function __construct(
        private readonly CapabilityMapBuilder $builder,
        private readonly TenantCache $cache,
    ) {}

    public function mapFor(User $user): CapabilityMap
    {
        $suffix = self::suffix($user->identifier());

        try {
            $cached = $this->cache->get($suffix);
            if (is_array($cached)) {
                $map = $this->decode($cached);
                if ($map !== null) {
                    return $map;
                }
            }
        } catch (Throwable) {
            return $this->degraded($user);
        }

        $map = $this->builder->build($user);

        try {
            $this->cache->put($suffix, $this->encode($map), self::TTL_SECONDS, 'rbac.* mutations via CapabilityCacheInvalidator');
        } catch (Throwable) {
            $this->recordOutage();
        }

        return $map;
    }

    /**
     * The cache entry is PRIMITIVES ONLY — the platform's cache refuses to
     * unserialize objects (`serializable_classes => false`, the gadget-chain
     * defense), and SEC-05 wants exactly that: a poisoned entry can at worst
     * be a malformed array, which decode() treats as a miss and rebuilds
     * server-side.
     *
     * @return array{v: array<string, array{0: bool, 1: string, 2: string|null, 3: string|null}>, s: array<string, string>}
     */
    private function encode(CapabilityMap $map): array
    {
        $verdicts = [];
        foreach ($map->verdicts() as $key => $verdict) {
            $verdicts[$key] = [
                $verdict->allowed,
                $verdict->source->value,
                $verdict->grantedBy,
                $verdict->expiresAt?->format(\DateTimeInterface::ATOM),
            ];
        }

        return ['v' => $verdicts, 's' => $map->scopes()];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function decode(array $data): ?CapabilityMap
    {
        if (! isset($data['v'], $data['s']) || ! is_array($data['v']) || ! is_array($data['s'])) {
            return null;
        }

        try {
            $verdicts = [];
            /** @var array<string, array{0: bool, 1: string, 2: string|null, 3: string|null}> $rows */
            $rows = $data['v'];
            foreach ($rows as $key => $row) {
                $verdicts[$key] = new Verdict(
                    $row[0],
                    VerdictSource::from($row[1]),
                    $row[2],
                    $row[3] === null ? null : new \DateTimeImmutable($row[3]),
                );
            }
            /** @var array<string, string> $scopes */
            $scopes = $data['s'];

            return new CapabilityMap($verdicts, $scopes);
        } catch (Throwable) {
            return null;
        }
    }

    public function verdictFor(User $user, string $key): Verdict
    {
        if (in_array($key, self::CACHE_BYPASS_KEYS, true)) {
            return $this->builder->build($user)->verdict($key);
        }

        return $this->mapFor($user)->verdict($key);
    }

    public static function suffix(string $userId): string
    {
        return 'cap:'.$userId;
    }

    /**
     * FS-01: the cache is unreachable — resolve from the database, alert, and
     * change nothing about the answer.
     */
    private function degraded(User $user): CapabilityMap
    {
        $this->recordOutage();

        return $this->builder->build($user);
    }

    private function recordOutage(): void
    {
        Log::warning('capability cache unreachable — resolving per request from the database', [
            'security_metric' => 'rbac.capability_cache_unavailable',
        ]);
    }
}
