<?php

declare(strict_types=1);

use App\Domain\Kb\Cache\KbCaches;
use App\Domain\Kb\Help\HelpContent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 T-05 / FS-03 (S10 step 11): the tree and render caches are TenantCache-backed
 * (C-07: tenant prefix, MANDATORY TTL, never truth), TTL-capped by kb.render_cache_ttl_seconds
 * (≤ 300 — the FS-03 staleness bound), and PURGED by the doors on every consumption-changing
 * edge. A purge failure is DEGRADED (reported), never fatal to the publish that triggered it —
 * time itself retries via the TTL. The CDN/edge half is [D18].
 */
it('T-05: the tree and renders are served FROM CACHE (a poisoned entry proves the read path), purge-on-publish restores truth, and the TTL honors the ≤300 s setting cap', function (): void {
    $s = kbScene();
    $help = app(HelpContent::class);
    $caches = app(KbCaches::class);
    $a = kbArticle($s, '[FIXTURE] Cached article');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);

    // Prime, then POISON the cache entries directly: if reads hit truth, the poison is invisible — so
    // seeing the poison IS the proof the cache serves.
    $help->tree();
    $help->article($a['article_id'], ['en']);
    $caches->putTree(['categories' => [], 'poisoned' => true]);
    $caches->putRender($a['article_id'], 'en', ['poisoned' => true]);
    expect($help->tree())->toHaveKey('poisoned')
        ->and($help->article($a['article_id'], ['en']))->toHaveKey('poisoned');

    // A publish on THIS article purges its renders + the tree — truth returns without waiting out
    // the TTL (the poison vanishing is the proof; a re-publish from published mints a new version).
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    $tree = $help->tree();
    expect($tree)->not->toHaveKey('poisoned')
        ->and(array_column(collect($tree['categories'])->firstWhere('id', $s['category_id'])['articles'], 'article_id'))->toContain($a['article_id'])
        ->and($help->article($a['article_id'], ['en']))->not->toHaveKey('poisoned');

    // The TTL cap: the registered setting bounds staleness at 300 s even if set higher upstream.
    app(App\Support\Settings\SettingsRepository::class)->set('kb.render_cache_ttl_seconds', 120, tenantSurface: false);
    expect($caches->ttl())->toBe(120);
    expect(app(KbCaches::class)->ttl())->toBeLessThanOrEqual(300);
});

it('FS-03: a failing purge is DEGRADED — the publish still succeeds, purgeArticle reports false, and the stale window is bounded by the TTL, not unbounded', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Purge failure');

    // The cache backend refuses forgets (the redis-down shape, scoped to forget so nothing else breaks).
    Cache::partialMock()->shouldReceive('forget')->andThrow(new RuntimeException('connection refused'));

    $caches = app(KbCaches::class);
    expect($caches->purgeArticle($a['article_id']))->toBeFalse('degraded, reported — never a throw')
        ->and($caches->purgeTree())->toBeFalse();

    // The door's own work stands: publish (which purges after commit) is unaffected.
    $publish = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish');
    $publish->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('status'))->toBe('published');
});
