<?php

declare(strict_types=1);

namespace App\Domain\Kb\Feedback;

use App\Support\Cache\TenantCache;
use App\Support\Settings\SettingsRepository;

/**
 * Doc 10 SEC-05 / EC-10 (S10 step 10): feedback writes per visitor token per hour, counted on
 * redis-volatile through TenantCache (t:{tenant}: prefix, TTL mandatory, never truth). A refused
 * attempt is an ANOMALY signal (feedback bombing): counted for the stats header and logged; the
 * bus-level anomaly event is proposed as amendment A-48 (the family is closed at nine).
 */
final class VisitorLimiter
{
    public function __construct(private readonly TenantCache $cache, private readonly SettingsRepository $settings) {}

    /** @return bool true = allowed (the attempt consumed one unit) */
    public function allow(string $visitorHash): bool
    {
        $limitRaw = $this->settings->get('kb.feedback.rate_per_hour');
        $limit = is_int($limitRaw) ? $limitRaw : 20;
        $window = 'kb:feedback:rate:'.$visitorHash.':'.gmdate('YmdH');
        $count = $this->cache->increment($window, 1, 3600, 'ttl-only: the hour rolls');
        if ($count > $limit) {
            $this->cache->decrement($window, 1);
            $this->cache->increment('kb:feedback:anomalies:'.gmdate('Ymd'), 1, 86400 + 3600, 'ttl-only: the day rolls');

            return false;
        }

        return true;
    }

    /** Today's refused-attempt count (the stats header's anomaly cell; Doc 15's report half rides A-48). */
    public function anomaliesToday(): int
    {
        $v = $this->cache->get('kb:feedback:anomalies:'.gmdate('Ymd'));

        return is_numeric($v) ? (int) $v : 0;
    }

    /**
     * A-48 (granted in part): true exactly ONCE per visitor per UTC day — the kb.feedback.anomaly
     * emit gate, so a bombing run puts one event on the bus, not one per refused attempt.
     */
    public function firstRefusalToday(string $visitorHash): bool
    {
        return $this->cache->increment('kb:feedback:anomaly-flag:'.$visitorHash.':'.gmdate('Ymd'), 1, 86400 + 3600, 'ttl-only: the day rolls') === 1;
    }

    public function commentCap(): int
    {
        $raw = $this->settings->get('kb.feedback.comment_max_chars');

        return is_int($raw) ? $raw : 500;
    }
}
