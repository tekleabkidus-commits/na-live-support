<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-01 / EC-02 / EC-03 (S10 step 3): the tree is at most 3 deep (the CHECK
 * is physics, the door computes depth), names are per language, ai_usable is the
 * CATEGORY-level flag, deleting a category with articles is REFUSED with the
 * retrieval impact stated, reassign is the path, and the permission door is closed.
 */
it('FR-01: depth 3 is the ceiling (4 refused), names are per language, the tree lists in (depth, position) order, and only kb.categories.manage may write', function (): void {
    $s = kbScene();
    $admin = $s['admin'];
    $l2 = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => '[FIXTURE] Deposits'], 'slug' => 'deposits', 'parent_id' => $s['category_id'], 'position' => 2]);
    $l2->assertStatus(201);
    $l3 = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => '[FIXTURE] Telebirr'], 'slug' => 'telebirr', 'parent_id' => $l2->json('id')]);
    $l3->assertStatus(201);
    $l4 = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => '[FIXTURE] Too deep'], 'slug' => 'deep', 'parent_id' => $l3->json('id')]);
    $l4->assertStatus(422);
    expect($l4->json('detail'))->toContain('at most 3 deep');

    $tree = $this->actingAs($admin, 'staff')->getJson('https://localhost/admin/kb/categories');
    expect(array_column($tree->json('categories'), 'slug'))->toBe(['payments', 'deposits', 'telebirr'])
        ->and($tree->json('categories.0.name.am'))->toBe('ክፍያዎች')
        ->and($tree->json('categories.1.depth'))->toBe(2);

    bindTenant($s['tenant']);
    $reader = kbActor([], 'reader');
    $this->actingAs($reader, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => 'x'], 'slug' => 'x'])->assertStatus(403);
    $this->actingAs($reader, 'staff')->getJson('https://localhost/admin/kb/categories')->assertOk();
});

it('EC-03: a category holding articles is NOT deleted — the refusal names the article count and the retrieval impact; reassign moves them (audited with the impact), then delete is a soft delete', function (): void {
    $s = kbScene(aiUsable: true);
    $admin = $s['admin'];
    $other = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => '[FIXTURE] Archive'], 'slug' => 'archive', 'ai_usable' => false]);
    bindTenant($s['tenant']);
    $article = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/articles', ['category_id' => $s['category_id'], 'audience' => 'public', 'source_language' => 'en', 'title' => '[FIXTURE] How deposits work']);
    $article->assertStatus(201);
    bindTenant($s['tenant']);
    DB::table('kb_article_variants')->where('id', $article->json('variant_id'))->update(['status' => 'published']); // scene: a served article (the workflow door is step 4)

    $refused = $this->actingAs($admin, 'staff')->deleteJson('https://localhost/admin/kb/categories/'.$s['category_id']);
    $refused->assertStatus(409);
    expect($refused->json('code'))->toBe('kb_category_has_articles')
        ->and($refused->json('articles'))->toBe(1)
        ->and($refused->json('retrieval_impact'))->toBe(1);

    $impact = $this->actingAs($admin, 'staff')->getJson('https://localhost/admin/kb/categories/'.$s['category_id'].'/impact?to='.$other->json('id'));
    expect($impact->json('retrieval_impact'))->toBe(1, 'moving to a non-ai_usable category takes one published public article out of the chatbot knowledge');

    $moved = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories/'.$s['category_id'].'/reassign', ['to' => $other->json('id')]);
    expect($moved->json('moved'))->toBe(1);
    bindTenant($s['tenant']);
    expect(DB::table('kb_articles')->where('id', $article->json('article_id'))->value('category_id'))->toBe($other->json('id'));
    $audit = DB::table('audit_log')->where('action_key', 'kb.category.reassigned')->first();
    expect(json_decode((string) $audit->payload, true)['retrieval_impact'])->toBe(1);

    $this->actingAs($admin, 'staff')->deleteJson('https://localhost/admin/kb/categories/'.$s['category_id'])->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_categories')->where('id', $s['category_id'])->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('kb_categories')->where('id', $s['category_id'])->exists())->toBeTrue('soft delete — the row survives (A-43)');
});
