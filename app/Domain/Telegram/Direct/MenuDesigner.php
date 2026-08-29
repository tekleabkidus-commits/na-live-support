<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Direct;

use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-27/T-06: the menu designer backend. Publish is VERSIONED with instant
 * rollback (a pointer move over the immutable telegram_menu_versions store),
 * and the published definition is cached per bot/language in redis-volatile;
 * publish INVALIDATES the cache so the next customer interaction sees it —
 * NFR-05's ≤10 s propagation (measured, config-cache invalidation).
 *
 * Limits (FR-27): ≤12 buttons, rows of ≤3. Five target types resolve through
 * the SEC-05 indirection table (telegram_flow_targets) — never executed as
 * flow ids directly.
 */
final class MenuDesigner
{
    private const CONNECTION = 'volatile';

    private const MAX_BUTTONS = 12;

    private const MAX_ROW = 3;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
    ) {}

    /**
     * Publish the draft as a new immutable version and point the menu at it;
     * invalidate the config cache. Returns the version number.
     *
     * @param  list<array{row: int, buttons: list<array{label: string, target_key: string}>}>  $definition
     */
    public function publish(string $menuId, string $publishedBy, array $definition): int
    {
        $this->validate($definition);

        return DB::transaction(function () use ($menuId, $publishedBy, $definition): int {
            $maxVersion = DB::table('telegram_menu_versions')->where('menu_id', $menuId)->max('version');
            $next = (is_numeric($maxVersion) ? (int) $maxVersion : 0) + 1;

            $versionId = (string) Str::uuid7();
            DB::table('telegram_menu_versions')->insert([
                'id' => $versionId,
                'tenant_id' => $this->context->tenantId(),
                'menu_id' => $menuId,
                'version' => $next,
                'definition' => json_encode($definition),
                'published_by' => $publishedBy,
            ]);

            DB::table('telegram_menus')->where('id', $menuId)->update([
                'active_version_id' => $versionId,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);

            $this->invalidate($menuId);

            return $next;
        });
    }

    /** Instant rollback: point the menu at an earlier version, invalidate cache. */
    public function rollback(string $menuId, int $toVersion): void
    {
        $versionId = DB::table('telegram_menu_versions')
            ->where('menu_id', $menuId)->where('version', $toVersion)->value('id');

        if (! is_string($versionId)) {
            throw new DomainException('cannot roll back to a version that was never published.');
        }

        DB::table('telegram_menus')->where('id', $menuId)->update([
            'active_version_id' => $versionId,
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
        $this->invalidate($menuId);
    }

    /**
     * The published menu as the next customer interaction resolves it: cache
     * first (NFR-05), else the DB, warming the cache.
     *
     * @return array<mixed>|null
     */
    public function activeDefinition(string $menuId): ?array
    {
        $cached = $this->redis->connection(self::CONNECTION)->command('get', [$this->cacheKey($menuId)]);
        if (is_string($cached)) {
            /** @var array<mixed> $decoded */
            $decoded = json_decode($cached, true);

            return $decoded;
        }

        $versionId = DB::table('telegram_menus')->where('id', $menuId)->value('active_version_id');
        if (! is_string($versionId)) {
            return null;
        }
        $json = DB::table('telegram_menu_versions')->where('id', $versionId)->value('definition');
        if (! is_string($json)) {
            return null;
        }

        $this->redis->connection(self::CONNECTION)->command('set', [$this->cacheKey($menuId), $json, ['EX' => 600]]);

        /** @var array<mixed> $decoded */
        $decoded = json_decode($json, true);

        return $decoded;
    }

    public function isCacheWarm(string $menuId): bool
    {
        return (bool) $this->redis->connection(self::CONNECTION)->command('exists', [$this->cacheKey($menuId)]);
    }

    /**
     * P7 (S10 COUNTERSIGNED, 2026-08-30): bind a button's target — the door Doc 12's builder UI
     * will mount. KB kinds validate EXISTENCE against the kb tables at bind time ONLY (published-
     * ness is render-time truth: an article that later unpublishes degrades at render via the
     * tombstone key, never strands the menu). The widened CHECK is the physics behind this
     * validation; refs stay opaque (SEC-05 — never executed as flow ids). Upsert on (bot, key):
     * re-binding a button replaces its target.
     */
    public function bindTarget(string $botId, string $key, string $targetType, ?string $targetRef): void
    {
        if ($targetType === 'kb_article' || $targetType === 'kb_category') {
            $table = $targetType === 'kb_article' ? 'kb_articles' : 'kb_categories';
            $exists = $targetRef !== null
                && DB::table($table)->where('id', $targetRef)->whereNull('deleted_at')->exists();
            if (! $exists) {
                throw new DomainException(sprintf('FR-19: a %s target must name an existing row (existence only — published-ness is render-time truth).', $targetType));
            }
        }
        $existing = DB::table('telegram_flow_targets')->where('bot_id', $botId)->where('key', $key)->value('id');
        if (is_string($existing)) {
            DB::table('telegram_flow_targets')->where('id', $existing)->update(['target_type' => $targetType, 'target_ref' => $targetRef]);
        } else {
            DB::table('telegram_flow_targets')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $this->context->tenantId(),
                'bot_id' => $botId, 'key' => $key, 'target_type' => $targetType, 'target_ref' => $targetRef,
            ]);
        }
    }

    /** @param list<array{row: int, buttons: list<array{label: string, target_key: string}>}> $definition */
    private function validate(array $definition): void
    {
        $total = 0;
        foreach ($definition as $row) {
            $count = count($row['buttons']);
            if ($count > self::MAX_ROW) {
                throw new DomainException(sprintf('FR-27: a menu row may hold at most %d buttons.', self::MAX_ROW));
            }
            $total += $count;
        }
        if ($total > self::MAX_BUTTONS) {
            throw new DomainException(sprintf('FR-27: a menu may hold at most %d buttons.', self::MAX_BUTTONS));
        }
    }

    private function invalidate(string $menuId): void
    {
        $this->redis->connection(self::CONNECTION)->command('del', [$this->cacheKey($menuId)]);
    }

    private function cacheKey(string $menuId): string
    {
        return sprintf('t:%s:tg-menu:%s', $this->context->tenantId(), $menuId);
    }
}
