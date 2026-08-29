<?php

declare(strict_types=1);

use App\Domain\Kb\Telegram\KbFlowRenderer;
use App\Domain\Kb\Tombstones\CitationUrls;
use Illuminate\Support\Facades\Lang;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-19 / BR-04 / BR-01 / BR-05 (S10 step 11): the bot-menu RENDERING CONTRACT.
 * kb_category → NUMBERED options over public published articles only; kb_article →
 * SUMMARY + LINK, the full text NEVER rides the chat; retired → tombstone key, never
 * silence; strings are kb.flow.* keys with en AND am. The Nutgram rendering + the
 * telegram_flow_targets CHECK expansion are Doc 05/12's — packet P7 ([D05]/[D12]).
 */
it('FR-19/BR-04: a category renders numbered public-published options (one per article); an article renders summary + link and NEVER its blocks; a retired article answers the tombstone key; BR-05 serves en for a missing am', function (): void {
    $s = kbScene();
    $renderer = app(KbFlowRenderer::class);
    $a = kbArticle($s, '[FIXTURE] Withdrawal limits');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    $b = kbArticle($s, '[FIXTURE] Deposit guide');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$b['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    $internal = kbArticle($s, '[FIXTURE] Internal playbook', 'internal');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$internal['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    kbArticle($s, '[FIXTURE] Draft only');

    $category = $renderer->forCategory($s['category_id'], ['en']);
    expect(array_column($category['options'], 'n'))->toBe([1, 2], 'numbered, and ONLY the two public published articles')
        ->and(array_column($category['options'], 'article_id'))->toContain($a['article_id'])->toContain($b['article_id'])
        ->and(array_column($category['options'], 'article_id'))->not->toContain($internal['article_id'])
        ->and($category['title_key'])->toBe('kb.flow.category_title')->and($category['empty_key'])->toBeNull();

    // BR-04: summary + link; the block text is structurally absent from the contract.
    $article = $renderer->forArticle($a['article_id'], ['am', 'en']); // BR-05: am missing → en serves
    expect($article['language'])->toBe('en')
        ->and($article['link'])->toBe(CitationUrls::article('en', (string) Illuminate\Support\Facades\DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('slug')))
        ->and($article['summary'])->not->toBeNull()->and($article)->not->toHaveKey('blocks')
        ->and(json_encode($article))->not->toContain('telebirr settle within 30 minutes'); // the full text never rides the chat

    // Tombstone, never silence: unpublish → the article answer names the retired key.
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/unpublish')->assertOk();
    bindTenant($s['tenant']);
    $retired = $renderer->forArticle($a['article_id'], ['en']);
    expect($retired['tombstone_key'])->toBe('kb.tombstone.retired')->and($retired['link'])->toBeNull();
    // And the category list shrinks with it (BR-01 everywhere).
    expect(array_column($renderer->forCategory($s['category_id'], ['en'])['options'], 'article_id'))->not->toContain($a['article_id']);

    // The unknown-category edge answers a key too.
    expect($renderer->forCategory('018f6b2e-0000-7000-8000-00000000dead', ['en'])['empty_key'])->toBe('kb.flow.category_unknown');

    // C-08: every contract key exists in BOTH launch languages.
    foreach (['kb.flow.category_title', 'kb.flow.category_empty', 'kb.flow.category_unknown', 'kb.flow.article_summary', 'kb.flow.start_chat'] as $key) {
        expect(Lang::has($key, 'en'))->toBeTrue($key.' en')->and(Lang::has($key, 'am'))->toBeTrue($key.' am');
    }
});
