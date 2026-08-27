<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-02 / BR-03 / §14 / EC-02 (S10 step 3): one article = N variants sharing an
 * id; audience is ARTICLE-level and cannot be disagreed with per variant (no column
 * exists); status is VARIANT-level; per-language slugs with the Amharic
 * transliteration helper; one variant per language; an internal article is ABSENT
 * (404) for a reader without kb.view_internal; a move into a non-ai_usable category
 * retires the chunks (EC-02 = the unpublish path).
 */
it('FR-02/BR-03/§14: an Amharic-first article gets a transliterated slug, variants share the id, status is per variant, audience is per article (physics), one variant per language', function (): void {
    $s = kbScene();
    $admin = $s['admin'];
    $created = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/articles', ['category_id' => $s['category_id'], 'audience' => 'internal', 'source_language' => 'am', 'title' => 'የተቀማጭ መመሪያ', 'tags' => ['deposits']]);
    $created->assertStatus(201);
    expect($created->json('slug'))->toBe('yeteqemach-memeriya'); // የተቀማጭ መመሪያ, deterministic
    bindTenant($s['tenant']);

    $en = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/articles/'.$created->json('article_id').'/variants', ['language' => 'en', 'title' => '[FIXTURE] Deposit guide']);
    $en->assertStatus(201);
    expect($en->json('slug'))->toBe('fixture-deposit-guide');
    bindTenant($s['tenant']);
    $dup = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/articles/'.$created->json('article_id').'/variants', ['language' => 'en', 'title' => 'again']);
    $dup->assertStatus(422);
    expect($dup->json('detail'))->toContain('one variant per language');
    bindTenant($s['tenant']);

    $columns = array_map(fn ($r) => $r->column_name, DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'kb_article_variants'"));
    expect($columns)->not->toContain('audience', 'BR-03 is physics: no per-variant audience column exists');
    DB::table('kb_article_variants')->where('id', $en->json('variant_id'))->update(['status' => 'published']);
    $shown = $this->actingAs($admin, 'staff')->getJson('https://localhost/admin/kb/articles/'.$created->json('article_id'));
    expect(array_column($shown->json('variants'), 'status', 'language'))->toBe(['am' => 'draft', 'en' => 'published'])
        ->and($shown->json('article.audience'))->toBe('internal')
        ->and($shown->json('article.source_language'))->toBe('am');

    bindTenant($s['tenant']);
    $reader = kbActor([], 'reader');
    $this->actingAs($reader, 'staff')->getJson('https://localhost/admin/kb/articles/'.$created->json('article_id'))->assertStatus(404); // absent, not forbidden (C-10)
    bindTenant($s['tenant']);
    try {
        DB::table('kb_articles')->where('id', $created->json('article_id'))->update(['audience' => 'mixed']);
        $this->fail('an out-of-enum audience was accepted');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('kb_articles_audience_check');
    }
});

it('EC-02: moving an article into a non-ai_usable category retires its chunks on the unpublish path and flags nothing as served; moving back marks it index-pending', function (): void {
    $s = kbScene(aiUsable: true);
    $admin = $s['admin'];
    $cold = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => '[FIXTURE] Legal'], 'slug' => 'legal', 'ai_usable' => false]);
    bindTenant($s['tenant']);
    $article = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/articles', ['category_id' => $s['category_id'], 'audience' => 'public', 'source_language' => 'en', 'title' => '[FIXTURE] Bonus terms']);
    bindTenant($s['tenant']);
    $articleId = $article->json('article_id');
    $variantId = $article->json('variant_id');
    DB::table('kb_article_variants')->where('id', $variantId)->update(['status' => 'published', 'index_status' => 'current', 'retrieval_generation' => 1]);
    $vector = array_fill(0, 1024, 0.0);
    $vector[2] = 1.0;
    app(App\Domain\Ai\Retrieval\KbIndexer::class)->index($articleId, 0, 'en', '[FIXTURE] Bonus wagering must complete within 7 days.', fixtureVector: $vector, variantId: $variantId, versionId: $variantId, generation: 1);
    expect(DB::table('kb_chunks')->where('article_ref', $articleId)->value('retrievable'))->toBeTrue();

    $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/articles/'.$articleId.'/move', ['category_id' => $cold->json('id')])->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_chunks')->where('article_ref', $articleId)->value('retrievable'))->toBeFalse('EC-02: chunks retired on the move')
        ->and(DB::table('kb_chunks')->where('article_ref', $articleId)->count())->toBe(1, 'retired, never deleted')
        ->and(DB::table('kb_article_variants')->where('id', $variantId)->value('index_status'))->toBe('none');

    $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/articles/'.$articleId.'/move', ['category_id' => $s['category_id']])->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_variants')->where('id', $variantId)->value('index_status'))->toBe('pending', 'back into scope = re-index through the pipeline, never served stale');
});
