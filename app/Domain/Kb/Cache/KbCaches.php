<?php

declare(strict_types=1);

namespace App\Domain\Kb\Cache;

use App\Support\Cache\TenantCache;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\Log;

/**
 * Doc 10 T-05 / FS-03 (S10 step 11) — the two KB consumption caches, one purge seam.
 * Category tree (the widget/Telegram browse shape) and per-article renders live on
 * redis-volatile through TenantCache (C-07: tenant prefix, MANDATORY TTL, never truth).
 * TTL = kb.render_cache_ttl_seconds (validated ≤ 300 in the settings registry), which
 * is ALSO the FS-03 staleness bound: a failed purge is retried by time itself — stale
 * is capped at five minutes, and the failure is reported (log + degraded flag), never
 * fatal to the door that published. Invalidation events: publish / unpublish / archive /
 * visibility / category change (Transitions + CategoryTree/Visibility call purge).
 * The CDN/edge half of T-05 rides [D18].
 */
final class KbCaches
{
    public function __construct(private readonly TenantCache $cache, private readonly SettingsRepository $settings) {}

    public function ttl(): int
    {
        $raw = $this->settings->get('kb.render_cache_ttl_seconds');

        return min(is_int($raw) ? $raw : 300, 300);
    }

    /** @return array<string, mixed>|null */
    public function tree(): ?array
    {
        return self::stringKeyed($this->cache->get('kb:help:tree'));
    }

    /** @param array<string, mixed> $tree */
    public function putTree(array $tree): void
    {
        $this->cache->put('kb:help:tree', $tree, $this->ttl(), 'TTL — FS-03 staleness bound; purged on publish/category change');
    }

    /** @return array<string, mixed>|null */
    public function render(string $articleId, string $language): ?array
    {
        return self::stringKeyed($this->cache->get('kb:render:'.$articleId.':'.$language));
    }

    /**
     * The cache is redis, not truth (C-07): whatever comes back is re-shaped, never trusted as typed.
     *
     * @return array<string, mixed>|null
     */
    private static function stringKeyed(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /** @param array<string, mixed> $payload */
    public function putRender(string $articleId, string $language, array $payload): void
    {
        $this->cache->put('kb:render:'.$articleId.':'.$language, $payload, $this->ttl(), 'TTL — FS-03 staleness bound; purged on publish/retire');
    }

    /**
     * Purge an article's renders (every ref language) + the tree. NEVER fatal: the TTL is
     * the FS-03 backstop, so a purge failure logs, reports degraded, and the door's own
     * work stands (stale ≤ kb.render_cache_ttl_seconds).
     *
     * @return bool true = purged; false = degraded (reported)
     */
    public function purgeArticle(string $articleId): bool
    {
        try {
            foreach ($this->languages() as $language) {
                $this->cache->forget('kb:render:'.$articleId.':'.$language);
            }
            $this->cache->forget('kb:help:tree');

            return true;
        } catch (\Throwable $e) {
            Log::warning('kb.cache.purge_failed', ['article_id' => $articleId, 'error' => $e->getMessage(), 'staleness_bound_seconds' => 300]);

            return false;
        }
    }

    /** @return bool true = purged; false = degraded (reported) */
    public function purgeTree(): bool
    {
        try {
            $this->cache->forget('kb:help:tree');

            return true;
        } catch (\Throwable $e) {
            Log::warning('kb.cache.purge_failed', ['scope' => 'tree', 'error' => $e->getMessage(), 'staleness_bound_seconds' => 300]);

            return false;
        }
    }

    /** @return list<string> */
    private function languages(): array
    {
        /** @var list<string> $codes */
        $codes = \Illuminate\Support\Facades\DB::table('ref_languages')->pluck('code')->all();

        return $codes;
    }
}
