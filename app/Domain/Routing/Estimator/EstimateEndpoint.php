<?php

declare(strict_types=1);

namespace App\Domain\Routing\Estimator;

use App\Support\Cache\TenantCache;
use App\Support\Settings\SettingsRepository;

/**
 * Doc 11 T-06 — the CACHED estimate for a queue, "exposed internally to Doc 09/13":
 * `routing.estimator.cache_seconds` (30, NFR-04's staleness bound) on the C-07 cache
 * (`t:{tenant}:routing:estimate:{queue}`, TTL mandatory, invalidated by its own TTL — the
 * band is a rolling view, not truth). The payload is the SEC-05 shape and nothing more.
 */
final class EstimateEndpoint
{
    public function __construct(
        private readonly Estimator $estimator,
        private readonly TenantCache $cache,
        private readonly SettingsRepository $settings,
    ) {}

    /** @return array{band: string, degraded: bool} */
    public function forQueue(string $queueId): array
    {
        $ttl = $this->settings->get('routing.estimator.cache_seconds');
        $ttl = is_numeric($ttl) ? max(5, min(30, (int) $ttl)) : 30;
        /** @var array{band: string, degraded: bool} $estimate */
        $estimate = $this->cache->remember('routing:estimate:'.$queueId, $ttl, 'ttl:routing.estimator.cache_seconds', fn (): array => $this->estimator->forQueue($queueId));

        return ['band' => $estimate['band'], 'degraded' => (bool) $estimate['degraded']];
    }
}
