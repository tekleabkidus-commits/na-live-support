<?php

declare(strict_types=1);

namespace App\Domain\Ai\Concurrency;

use App\Support\Cache\TenantCache;
use App\Support\Settings\SettingsRepository;

/**
 * FR-03's per-tenant concurrency semaphore (Redis volatile via TenantCache
 * — C-07 prefixing and mandatory TTLs come free). Slots self-expire so a
 * crashed job can never leak a slot forever; the TTL is generous against
 * the longest task timeout.
 */
final class TenantSemaphore
{
    private const SLOT_TTL_SECONDS = 120;

    public function __construct(
        private readonly TenantCache $cache,
        private readonly SettingsRepository $settings,
    ) {}

    public function acquire(): bool
    {
        $raw = $this->settings->get('ai.concurrency.per_tenant');
        $limit = is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : 20);

        $held = $this->cache->increment('ai:semaphore', 1, self::SLOT_TTL_SECONDS, 'slot self-expiry');

        if ($held > $limit) {
            $this->cache->decrement('ai:semaphore', 1);

            return false;
        }

        return true;
    }

    public function release(): void
    {
        $this->cache->decrement('ai:semaphore', 1);
    }
}
