<?php

declare(strict_types=1);

use App\Domain\Kb\Help\HelpContent;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Kb/KbSceneHelpers.php';

/**
 * Doc 10 SEC-03 (S10 step 11): article content is INERT on every surface — scripts, event
 * handlers and javascript: URLs are stripped AT SAVE (WorkingCopy → InertContent), so the
 * stored working copy, the minted immutable version, the staff read and the customer read
 * model all carry no live markup, and no surface "unwraps" anything at render time. The
 * feedback-comment half of SEC-03 is proven in KbFeedbackHostileInputTest.
 */
it('SEC-03: hostile markup dies at save — the working copy, the minted version, and the customer read model are all inert; nothing re-animates at render', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Hostile content');
    $hostile = '[FIXTURE] Click <script>alert(1)</script><a href="javascript:steal()" onclick="x()">here</a><style>*{display:none}</style> to deposit.';
    $this->actingAs($s['admin'], 'staff')->putJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/working-copy', ['content' => ['blocks' => [
        ['id' => 'b1', 'type' => 'paragraph', 'text' => $hostile],
    ]]])->assertOk();
    bindTenant($s['tenant']);

    // The STORED working copy is already inert (stripped at save, not at render).
    $stored = (string) DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('working_copy');
    expect($stored)->not->toContain('<script')->not->toContain('onclick')->not->toContain('javascript:')->not->toContain('<style')
        ->and($stored)->toContain('[FIXTURE] Click'); // the text survives; only the live markup dies

    $published = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish');
    $published->assertOk();
    bindTenant($s['tenant']);

    // The immutable version snapshot is inert too — what the customer surfaces serve forever.
    $version = (string) DB::table('kb_article_versions')->where('id', $published->json('version_id'))->value('content');
    expect($version)->not->toContain('<script')->not->toContain('onclick')->not->toContain('javascript:');

    // The customer read model (widget/Telegram contract) renders what is stored — inert, no unwrap.
    $served = app(HelpContent::class)->article($a['article_id'], ['en']);
    expect(json_encode($served))->not->toContain('<script')->not->toContain('onclick')->not->toContain('javascript:');
});
