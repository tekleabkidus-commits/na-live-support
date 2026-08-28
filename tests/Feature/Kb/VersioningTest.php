<?php

declare(strict_types=1);

use App\Domain\Kb\Versioning\BlockDiff;
use App\Domain\Kb\Versioning\Versions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-08 / NFR-04 / AC-07 (S10 step 4): every publish mints an immutable full
 * snapshot (author, note, content hash — T-02 determinism), the block-aware diff on a
 * fixture set (changed / added / removed, paired by block id, positional fallback),
 * restore = a NEW DRAFT working copy (history untouched), 1k versions listed by cursor
 * without degradation. DB-level immutability is KbGrantPhysicsTest's (no UPDATE grant).
 */
it('publish mints versions 1..n with author/note/hash; the diff pairs blocks by id — changed, added, removed; restore writes the working copy and leaves history intact', function (): void {
    $s = kbScene();
    $a = kbArticle($s);
    $url = 'https://localhost/admin/kb/variants/'.$a['variant_id'];
    $v1 = $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish', ['note' => 'first']);
    $v1->assertOk();
    bindTenant($s['tenant']);
    $this->actingAs($s['admin'], 'staff')->putJson($url.'/working-copy', ['content' => ['blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => '[FIXTURE] Deposits with telebirr settle within 10 minutes.'], ['id' => 'b2', 'type' => 'paragraph', 'text' => '[FIXTURE] Minimum deposit 50 ETB.']]]])->assertOk();
    $v2 = $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish', ['note' => 'second']);
    $v2->assertOk();
    expect($v2->json('version'))->toBe(2);

    $diff = $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/versions/'.$v1->json('version_id').'/diff?to='.$v2->json('version_id'));
    $diff->assertOk();
    expect($diff->json('summary'))->toBe(['added' => 1, 'removed' => 0, 'changed' => 1, 'unchanged' => 0])
        ->and(array_column($diff->json('entries'), 'kind', 'key'))->toBe(['id:b1' => 'changed', 'id:b2' => 'added']);

    $history = $this->actingAs($s['admin'], 'staff')->getJson($url.'/versions');
    expect(array_column($history->json('data'), 'version'))->toBe([2, 1])
        ->and($history->json('data.1.note'))->toBe('first')
        ->and($history->json('data.1.author_id'))->toBe($s['admin']->identifier());

    $restored = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/versions/'.$v1->json('version_id').'/restore');
    $restored->assertOk()->assertJson(['restored_from' => 1, 'draft' => true]);
    bindTenant($s['tenant']);
    $copy = $this->actingAs($s['admin'], 'staff')->getJson($url.'/working-copy');
    expect($copy->json('content.blocks.0.text'))->toContain('30 minutes')->and(count($copy->json('content.blocks')))->toBe(1);
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_versions')->where('variant_id', $a['variant_id'])->count())->toBe(2, 'restore mints nothing and rewrites nothing')
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('current_version_id'))->toBe($v2->json('version_id'), 'the published pointer is untouched by a restore');
});

it('T-02 determinism + the diff fixture set: identical content → identical hash regardless of key order; positional pairing when blocks carry no ids; a removed block is reported', function (): void {
    expect(Versions::contentHash(['blocks' => [['type' => 'p', 'text' => 'a', 'id' => 'x']]]))->toBe(Versions::contentHash(['blocks' => [['id' => 'x', 'text' => 'a', 'type' => 'p']]]));
    $diff = (new BlockDiff)->diff(['blocks' => [['type' => 'p', 'text' => 'one'], ['type' => 'p', 'text' => 'two'], ['type' => 'p', 'text' => 'three']]], ['blocks' => [['type' => 'p', 'text' => 'one'], ['type' => 'p', 'text' => 'TWO']]]);
    expect($diff['summary'])->toBe(['added' => 0, 'removed' => 1, 'changed' => 1, 'unchanged' => 1])
        ->and(array_column($diff['entries'], 'kind', 'key'))->toBe(['pos:0' => 'unchanged', 'pos:1' => 'changed', 'pos:2' => 'removed']);
});

it('NFR-04: 1,000 versions on one variant list by cursor (limit ≤ 100) newest first without degradation', function (): void {
    $s = kbScene();
    $a = kbArticle($s);
    $rows = [];
    for ($n = 1; $n <= 1000; $n++) {
        $rows[] = ['id' => (string) new UuidV7, 'tenant_id' => $s['tenant_id'], 'variant_id' => $a['variant_id'], 'article_id' => $a['article_id'], 'version' => $n, 'language' => 'en', 'title' => 't', 'slug' => 's', 'summary' => '', 'content' => '{"blocks":[]}', 'content_hash' => 'h'.$n, 'audience_at_publish' => 'public', 'author_id' => $s['admin']->identifier()];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table('kb_article_versions')->insert($chunk);
    }
    $started = hrtime(true);
    $page = $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/versions?limit=100');
    $elapsedMs = (hrtime(true) - $started) / 1e6;
    $page->assertOk();
    expect($page->json('data'))->toHaveCount(100)->and($page->json('data.0.version'))->toBe(1000)->and($page->json('next_cursor'))->not->toBeNull();
    fwrite(STDERR, sprintf("\nNFR-04 history page (1k versions, limit 100): %.1f ms dev-indicative\n", $elapsedMs));
    $seen = 100;
    $cursor = $page->json('next_cursor');
    while ($cursor !== null && $seen < 1000) {
        bindTenant($s['tenant']);
        $next = $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/versions?limit=100&cursor='.urlencode($cursor));
        $next->assertOk();
        $seen += count($next->json('data'));
        $cursor = $next->json('next_cursor');
    }
    expect($seen)->toBe(1000);
    $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/versions?page=2')->assertStatus(400); // C-05: offset pagination refused
});
