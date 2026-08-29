<?php

declare(strict_types=1);

use App\Domain\Kb\Search\CopilotKbBlend;
use App\Domain\Kb\Search\KbSearchDocuments;
use App\Jobs\Kb\KbSearchIndexer;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-17 / FS-02 / SEC-06 (S10 step 10): Meilisearch `t{tenant}_kb_articles` fed by the durable
 * indexer (publish + retirement) and the writer doors (titles at creation); internal articles are
 * ABSENT without kb.view_internal (index-side filter); language/status/visibility filters; Meilisearch
 * down = `unavailable`, never a 500 or an empty lie; the create-from-query hint stores nothing (SEC-06);
 * the Copilot blend = retrieval first, keyword fills to three, no duplicate article.
 */
function runSearchIndexer(string $variantId, string $name = 'kb.article.published'): void
{
    $row = DB::table('outbox')->where('name', $name)->where('payload->variant_id', $variantId)->orderByDesc('id')->first();
    DB::table('events')->insertOrIgnore(['id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'schema_version' => $row->schema_version ?? 1, 'payload' => $row->payload]);
    (new KbSearchIndexer($row->id))->handle(app(KbSearchDocuments::class));
}

it('FR-17: publish → indexer → hits on title/body/tags; internal absent without kb.view_internal; filters; unpublish retires the hit from status=published; the query is stored nowhere (SEC-06)', function (): void {
    $s = kbScene();
    $pub = kbArticle($s, '[FIXTURE] Telebirr deposit guide');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$pub['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    runSearchIndexer($pub['variant_id']);
    $internal = kbArticle($s, '[FIXTURE] Telebirr internal playbook', 'internal');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$internal['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    runSearchIndexer($internal['variant_id']);

    $agent = kbActor([], 'agent');
    $r = $this->actingAs($agent, 'staff')->getJson('https://localhost/admin/kb/search?q=telebirr');
    $r->assertOk();
    expect($r->json('unavailable'))->toBeFalse()->and(array_column($r->json('hits'), 'article_id'))->toBe([$pub['article_id']], 'internal ABSENT for a reader without kb.view_internal');
    $insider = $this->actingAs($s['admin'], 'staff')->getJson('https://localhost/admin/kb/search?q=telebirr');
    bindTenant($s['tenant']);
    expect(array_column($insider->json('hits'), 'article_id'))->toContain($pub['article_id'])->toContain($internal['article_id']);
    $body = $this->actingAs($agent, 'staff')->getJson('https://localhost/admin/kb/search?q=settle&status=published&language=en&visibility=public');
    expect(array_column($body->json('hits'), 'article_id'))->toBe([$pub['article_id']], 'body text is searchable');
    expect($this->actingAs($agent, 'staff')->getJson('https://localhost/admin/kb/search?q=telebirr&language=am')->json('hits'))->toBe([]);

    $empty = $this->actingAs($agent, 'staff')->getJson('https://localhost/admin/kb/search?q=zebra+quantum');
    expect($empty->json('hits'))->toBe([])->and($empty->json('create_from_query'))->toBe('zebra quantum');
    // SEC-06: no table row and no cache key carries the query.
    bindTenant($s['tenant']);
    foreach (['kb_feedback', 'kb_articles', 'kb_article_variants', 'audit_log', 'outbox'] as $table) {
        $cols = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = ? AND data_type IN ('text','jsonb','character varying')", [$table]);
        foreach ($cols as $c) {
            expect(DB::table($table)->whereRaw('"'.$c->column_name.'"::text like ?', ['%zebra quantum%'])->exists())->toBeFalse($table.'.'.$c->column_name.' stored the query');
        }
    }

    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$pub['variant_id'].'/unpublish')->assertOk();
    bindTenant($s['tenant']);
    runSearchIndexer($pub['variant_id'], 'kb.retrieval.updated');
    expect($this->actingAs($agent, 'staff')->getJson('https://localhost/admin/kb/search?q=telebirr&status=published')->json('hits'))->toBe([], 'retired from the published filter')
        ->and(array_column($this->actingAs($agent, 'staff')->getJson('https://localhost/admin/kb/search?q=telebirr&status=draft')->json('hits'), 'article_id'))->toBe([$pub['article_id']]);
});

it('FS-02: Meilisearch unreachable → unavailable:true (no 500, no empty lie); the Copilot blend degrades to retrieval-only', function (): void {
    $s = kbScene();
    config(['services.meilisearch.host' => 'http://127.0.0.1:1']);
    $r = $this->actingAs(kbActor([], 'agent'), 'staff')->getJson('https://localhost/admin/kb/search?q=telebirr');
    $r->assertOk();
    expect($r->json('unavailable'))->toBeTrue()->and($r->json('hits'))->toBe([]);
    $blend = app(CopilotKbBlend::class)->top3('telebirr', [['article_ref' => 'a1', 'content' => '[FIXTURE] chunk']], 'en');
    expect($blend)->toHaveCount(1)->and($blend[0]['source'])->toBe('retrieval');
});

it('Copilot blend: retrieval chunks first (deduped by article), keyword hits fill to three, never a duplicate article, published-only', function (): void {
    $s = kbScene();
    $ids = [];
    foreach (['[FIXTURE] Withdrawal limits', '[FIXTURE] Withdrawal timing', '[FIXTURE] Withdrawal fees'] as $t) {
        $a = kbArticle($s, $t);
        $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
        bindTenant($s['tenant']);
        runSearchIndexer($a['variant_id']);
        $ids[] = $a['article_id'];
    }
    $draft = kbArticle($s, '[FIXTURE] Withdrawal draft');
    $chunks = [['article_ref' => $ids[0], 'content' => '[FIXTURE] limits chunk', 'version_id' => 'v1'], ['article_ref' => $ids[0], 'content' => '[FIXTURE] limits chunk 2', 'version_id' => 'v1']];
    $top = app(CopilotKbBlend::class)->top3('withdrawal', $chunks, 'en');
    expect($top)->toHaveCount(3)->and($top[0])->toMatchArray(['article_ref' => $ids[0], 'source' => 'retrieval', 'version_id' => 'v1'])
        ->and(array_column($top, 'article_ref'))->toHaveCount(3)->and(array_unique(array_column($top, 'article_ref')))->toHaveCount(3)
        ->and(array_column($top, 'article_ref'))->not->toContain($draft['article_id'])
        ->and(array_column(array_slice($top, 1), 'source'))->toBe(['keyword', 'keyword']);
});
