<?php

declare(strict_types=1);

use App\Domain\Kb\Tombstones\CitationResolver;
use App\Domain\Kb\Tombstones\CitationState;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * A-43 (approved) — article delete is a SOFT delete under kb.articles.delete: the row
 * survives (grant physics forbids DELETE anyway), chunks retire, once-cited URLs keep
 * resolving as tombstones (FR-16 survives deletion), audited; a reader without the key
 * is refused; the article vanishes from staff reads (absence).
 */
it('A-43: delete → soft, chunks retired, citations tombstone, audited, gated; the article is ABSENT afterwards', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Old promo');
    $published = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish');
    $published->assertOk();
    bindTenant($s['tenant']);
    $vector = array_fill(0, 1024, 0.0);
    $vector[6] = 1.0;
    app(App\Domain\Ai\Retrieval\KbIndexer::class)->index($a['article_id'], 0, 'en', '[FIXTURE] Old promo terms.', fixtureVector: $vector, variantId: $a['variant_id'], versionId: $published->json('version_id'), generation: 1);

    $editor = kbActor(['kb.articles.edit', 'kb.articles.publish'], 'editor');
    $deleter = kbActor(['kb.articles.delete'], 'deleter');
    $this->actingAs($editor, 'staff')->deleteJson('https://localhost/admin/kb/articles/'.$a['article_id'])->assertStatus(403);

    $this->actingAs($deleter, 'staff')->deleteJson('https://localhost/admin/kb/articles/'.$a['article_id'])->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_articles')->where('id', $a['article_id'])->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('kb_chunks')->where('article_ref', $a['article_id'])->where('retrievable', true)->count())->toBe(0)
        ->and(DB::table('kb_chunks')->where('article_ref', $a['article_id'])->count())->toBe(1, 'retired, never deleted')
        ->and(DB::table('audit_log')->where('action_key', 'kb.article.deleted')->where('entity_id', $a['article_id'])->exists())->toBeTrue()
        ->and(app(CitationResolver::class)->resolve($a['article_id']))->toBe(CitationState::Retired);
    $facts = $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/cite/'.$a['article_id'].'/'.$published->json('version_id'));
    $facts->assertOk()->assertJson(['state' => 'retired']);
    $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/articles/'.$a['article_id'])->assertStatus(404);
});
