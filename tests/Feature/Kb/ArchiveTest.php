<?php

declare(strict_types=1);

use App\Domain\Kb\Tombstones\CitationResolver;
use App\Domain\Kb\Tombstones\CitationState;
use App\Domain\Kb\Translation\VariantForLanguage;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-09 (S10 step 8 — the edge lives in Transitions since step 4, the sole status writer):
 * Published → Archived removes the variant from EVERY consumption surface built here — retrieval
 * (chunks flip `retrievable`, never deleted; kb.retrieval.updated status=retired in the flip's
 * transaction) and human reads (VariantForLanguage serves published only) — while once-cited URLs
 * keep resolving as tombstones (FR-16). Un-archive returns to DRAFT: still unserved, still
 * unindexed, must re-publish (content may be stale). Archive needs kb.articles.publish.
 */
it('FR-09: archive retires chunks + events + audits + tombstones; the variant is unserved; un-archive → draft that must re-publish', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Old bonus terms');
    $published = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish');
    $published->assertOk();
    bindTenant($s['tenant']);
    $vector = array_fill(0, 1024, 0.0);
    $vector[3] = 1.0;
    app(App\Domain\Ai\Retrieval\KbIndexer::class)->index($a['article_id'], 0, 'en', '[FIXTURE] Old bonus terms.', fixtureVector: $vector, variantId: $a['variant_id'], versionId: $published->json('version_id'), generation: 1);
    expect(app(VariantForLanguage::class)->resolve($a['article_id'], ['en'])['variant_id'])->toBe($a['variant_id'], 'positive control: served while published');

    $editor = kbActor(['kb.articles.edit'], 'editor');
    $this->actingAs($editor, 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/archive')->assertStatus(403);
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('status'))->toBe('published');

    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/archive')->assertOk()->assertJson(['status' => 'archived']);
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('status'))->toBe('archived')
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('index_status'))->toBe('none')
        ->and(DB::table('kb_chunks')->where('article_ref', $a['article_id'])->where('retrievable', true)->count())->toBe(0, 'removed from retrieval')
        ->and(DB::table('kb_chunks')->where('article_ref', $a['article_id'])->count())->toBe(1, 'retired, never deleted')
        ->and(DB::table('outbox')->where('name', 'kb.retrieval.updated')->where('payload->variant_id', $a['variant_id'])->where('payload->status', 'retired')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'kb.variant.archived')->where('entity_id', $a['variant_id'])->exists())->toBeTrue()
        ->and(app(VariantForLanguage::class)->resolve($a['article_id'], ['en']))->toBeNull('removed from human consumption')
        ->and(app(CitationResolver::class)->resolve($a['article_id']))->toBe(CitationState::Retired);
    $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/cite/'.$a['article_id'].'/'.$published->json('version_id'))->assertOk()->assertJson(['state' => 'retired']);

    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/unarchive')->assertOk()->assertJson(['status' => 'draft']);
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('status'))->toBe('draft')
        ->and(DB::table('kb_chunks')->where('article_ref', $a['article_id'])->where('retrievable', true)->count())->toBe(0, 'un-archive does NOT restore retrieval')
        ->and(app(VariantForLanguage::class)->resolve($a['article_id'], ['en']))->toBeNull('a draft is not served')
        ->and(DB::table('audit_log')->where('action_key', 'kb.variant.unarchived')->where('entity_id', $a['variant_id'])->exists())->toBeTrue();

    $again = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish');
    $again->assertOk();
    bindTenant($s['tenant']);
    expect($again->json('version_id'))->not->toBe($published->json('version_id'), 'a re-publish mints a NEW version')
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('index_status'))->toBe('pending', 'retrieval returns only through the publish pipeline')
        ->and(app(VariantForLanguage::class)->resolve($a['article_id'], ['en'])['variant_id'])->toBe($a['variant_id']);
});
