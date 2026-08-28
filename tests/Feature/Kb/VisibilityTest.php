<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-10 / EC-11 / SEC-04 / SCHEMA AMENDMENT 4 FLAG A (S10 step 4): a visibility
 * change is a PUBLISH, not a toggle — it goes through the ONE door, is audited
 * HIGH-VISIBILITY (old/new + actor), drops every published/draft variant to In review
 * with its chunks retired (the full pipeline incl. review applies), and nothing serves
 * under the new audience until re-published. The sole-writer sweep proves no other file
 * under app/ writes kb_articles.audience (sabotage #12's red).
 */
it('EC-11: internal → public through the door = high-visibility audit + every variant back to review + chunks retired; a same-state change is a noop; the door is kb.articles.publish', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Payout escalation playbook', 'internal');
    $url = 'https://localhost/admin/kb/variants/'.$a['variant_id'];
    $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish')->assertOk();
    bindTenant($s['tenant']);
    $vector = array_fill(0, 1024, 0.0);
    $vector[4] = 1.0;
    app(App\Domain\Ai\Retrieval\KbIndexer::class)->index($a['article_id'], 0, 'en', '[FIXTURE] Escalate payout disputes above 50,000 ETB to finance.', audience: 'internal', fixtureVector: $vector, variantId: $a['variant_id'], versionId: $a['variant_id'], generation: 1);

    $editor = kbActor(['kb.articles.edit'], 'editor');
    $this->actingAs($editor, 'staff')->postJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/visibility', ['audience' => 'public'])->assertStatus(403);

    $changed = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/visibility', ['audience' => 'public']);
    $changed->assertOk()->assertJson(['old' => 'internal', 'new' => 'public', 'variants_to_review' => 1]);
    bindTenant($s['tenant']);
    expect(DB::table('kb_articles')->where('id', $a['article_id'])->value('audience'))->toBe('public')
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('status'))->toBe('in_review', 'the full pipeline incl. review applies')
        ->and(DB::table('kb_chunks')->where('article_ref', $a['article_id'])->value('retrievable'))->toBeFalse('nothing serves under the new audience until re-published');
    $audit = DB::table('audit_log')->where('action_key', 'kb.article.visibility_changed')->where('entity_id', $a['article_id'])->first();
    expect($audit)->not->toBeNull();
    $payload = json_decode((string) $audit->payload, true);
    expect($payload['old']['audience'])->toBe('internal')->and($payload['new']['audience'])->toBe('public')->and($audit->actor_id)->toBe($s['admin']->identifier());

    $noop = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/visibility', ['audience' => 'public']);
    $noop->assertOk()->assertJson(['variants_to_review' => 0]);
    bindTenant($s['tenant']);
    expect(DB::table('audit_log')->where('action_key', 'kb.article.visibility_changed')->where('entity_id', $a['article_id'])->count())->toBe(1, 'a same-state change audits nothing');
});

it('FLAG A sole-writer sweep: Visibility.php is the ONLY file under app/ that UPDATES kb_articles.audience (the create door INSERTs it, nothing else writes it)', function (): void {
    $writers = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) php_strip_whitespace($file->getPathname());
        $rel = str_replace(base_path().'/', '', $file->getPathname());
        if (! str_contains($source, 'kb_articles')) {
            continue;
        }
        $updatesAudience = preg_match('/UPDATE\s+kb_articles\s+SET[^;]*\baudience\b/i', $source) === 1
            || (preg_match('/->update\(\s*\[[^\]]*[\'"]audience[\'"]\s*=>/s', $source) === 1);
        if ($updatesAudience) {
            $writers[] = $rel;
        }
    }
    expect($writers)->toBe(['app/Domain/Kb/Visibility/Visibility.php'], 'a second audience writer: '.implode(', ', $writers));
});
