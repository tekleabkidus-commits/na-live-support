<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';
/** Doc 10 AC-07 (S10 step 4; sabotage #5's runtime half, kept as the standing positive control): an in-place rewrite of a published version is REFUSED by the grant. */
it('AC-07 runtime: a published version cannot be rewritten in place', function (): void {
    $s = kbScene();
    $a = kbArticle($s);
    $v = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish');
    $v->assertOk();
    bindTenant($s['tenant']);
    try {
        DB::transaction(static fn () => DB::table('kb_article_versions')->where('id', $v->json('version_id'))->update(['content' => '{"blocks":[]}']));
        $this->fail('history was rewritten in place');
    } catch (Illuminate\Database\QueryException $e) {
        expect($e->getMessage())->toContain('permission denied');
    }
});
