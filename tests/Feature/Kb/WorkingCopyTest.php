<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-05 / EC-12 / FS-05 / SEC-03 (S10 step 3): autosave writes the WORKING
 * COPY (never a version), stamps working_copy_saved_at in SQL, recover returns the
 * copy + the stamp (the banner's fact), a stale expected stamp is a 409 conflict
 * (the optimistic fallback), and script/style are stripped at save.
 */
it('autosave: working copy + DB stamp, no version minted; recover returns the EC-12 fact; a stale expected_saved_at is a 409 kb_save_conflict; script/style stripped at save', function (): void {
    $s = kbScene();
    $admin = $s['admin'];
    $article = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/articles', ['category_id' => $s['category_id'], 'audience' => 'public', 'source_language' => 'en', 'title' => '[FIXTURE] Withdrawals']);
    bindTenant($s['tenant']);
    $variantId = $article->json('variant_id');

    $first = $this->actingAs($admin, 'staff')->putJson('https://localhost/admin/kb/variants/'.$variantId.'/working-copy', ['content' => ['blocks' => [['type' => 'paragraph', 'text' => 'Withdrawals to CBE arrive within one business day.<script>alert(1)</script>']]]]);
    $first->assertOk();
    $savedAt = $first->json('saved_at');
    expect($savedAt)->not->toBeNull();
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_versions')->where('variant_id', $variantId)->count())->toBe(0, 'autosave never mints a version (FR-05)');

    $recovered = $this->actingAs($admin, 'staff')->getJson('https://localhost/admin/kb/variants/'.$variantId.'/working-copy');
    expect($recovered->json('saved_at'))->toBe($savedAt)
        ->and($recovered->json('content.blocks.0.text'))->toBe('Withdrawals to CBE arrive within one business day.')
        ->and($recovered->json('content.blocks.0.text'))->not->toContain('<script');

    bindTenant($s['tenant']);
    $second = $this->actingAs($admin, 'staff')->putJson('https://localhost/admin/kb/variants/'.$variantId.'/working-copy', ['content' => ['blocks' => []], 'expected_saved_at' => $savedAt]);
    $second->assertOk();
    expect($second->json('saved_at'))->not->toBe($savedAt);
    bindTenant($s['tenant']);
    $stale = $this->actingAs($admin, 'staff')->putJson('https://localhost/admin/kb/variants/'.$variantId.'/working-copy', ['content' => ['blocks' => []], 'expected_saved_at' => $savedAt]);
    $stale->assertStatus(409);
    expect($stale->json('code'))->toBe('kb_save_conflict')->and($stale->json('current_saved_at'))->toBe($second->json('saved_at'));
});
