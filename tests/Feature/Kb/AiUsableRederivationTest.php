<?php

declare(strict_types=1);

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Retrieval\HybridRetriever;
use App\Domain\Kb\Publish\PublishPipeline;
use App\Jobs\Kb\KbIndexMaintenanceConsumer;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * SCHEMA AMENDMENT 3 FLAG 3's REOPENER — [D10] closing row 6 (S10 step 7), the RULING with
 * its proving test: the chunk-level `ai_usable` interim is RE-DERIVED from Doc 10's
 * CATEGORY flag (FR-01): at index time the chunk carries the category's flag; flipping the
 * category off retires every article's chunks on the unpublish path (EC-02's category
 * edge) and the chatbot can no longer reach them; flipping it back marks published
 * variants index-pending and the consumer re-indexes them with ai_usable=true. Nothing
 * ever sets the chunk flag by hand.
 */
function rederivationDriver(int $n): void
{
    $driver = new FakeDriver(new FixturesOnlyEgress);
    for ($i = 0; $i < $n; $i++) {
        $v = array_fill(0, 1024, 0.0);
        $v[7] = 1.0;
        $driver->queue(new ProviderResponse('', 0, 0, [], [$v]));
    }
    app(ProviderRegistry::class)->swap('fake-embed', $driver);
    config(['ai.default_routing.embeddings' => [['provider' => 'fake-embed', 'model' => 'e']]]);
}

function rederivationConsume(string $variantId): void
{
    $row = DB::table('outbox')->where('name', 'kb.article.published')->where('payload->variant_id', $variantId)->orderByDesc('id')->first();
    DB::table('events')->insertOrIgnore(['id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'schema_version' => $row->schema_version ?? 1, 'payload' => $row->payload]);
    (new KbIndexMaintenanceConsumer($row->id))->handle(app(PublishPipeline::class));
}

it('FLAG 3 closed by ruling: the chunk flag IS the category flag at index time; category off → chunks retired + unreachable; category on → re-indexed ai_usable=true', function (): void {
    $s = kbScene(aiUsable: true);
    rederivationDriver(6);
    $a = kbArticle($s, '[FIXTURE] Deposit limits');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    rederivationConsume($a['variant_id']);
    expect(DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->where('retrievable', true)->pluck('ai_usable')->unique()->all())->toBe([true]);
    rederivationDriver(1);
    expect(array_column(app(HybridRetriever::class)->retrieve('[FIXTURE] deposit limits', ['en'])['chunks'], 'article_ref'))->toContain($a['article_id']);

    // The category leaves the chatbot's scope: the same atomic path as unpublish (EC-02), audited old/new.
    $this->actingAs($s['admin'], 'staff')->patchJson('https://localhost/admin/kb/categories/'.$s['category_id'], ['ai_usable' => false])->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->where('retrievable', true)->count())->toBe(0)
        ->and(DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->count())->toBeGreaterThan(0, 'retired, never deleted')
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('status'))->toBe('published', 'humans still see it — only the chatbot scope changed');
    rederivationDriver(1);
    expect(array_column(app(HybridRetriever::class)->retrieve('[FIXTURE] deposit limits', ['en'])['chunks'], 'article_ref'))->not->toContain($a['article_id']);
    $audit = DB::table('audit_log')->where('action_key', 'kb.category.updated')->orderByDesc('occurred_at')->first();
    $payload = json_decode((string) $audit->payload, true);
    expect($payload['old']['ai_usable'])->toBeTrue()->and($payload['new']['ai_usable'])->toBeFalse();

    // Back into scope: published variants are re-indexed through the pipeline — never served stale.
    $this->actingAs($s['admin'], 'staff')->patchJson('https://localhost/admin/kb/categories/'.$s['category_id'], ['ai_usable' => true])->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('index_status'))->toBe('pending');
    rederivationDriver(6);
    $versionId = DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('current_version_id');
    app(PublishPipeline::class)->index($a['variant_id'], (string) $versionId); // the re-index the consumer would run
    expect(DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->where('retrievable', true)->pluck('ai_usable')->unique()->all())->toBe([true])
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('index_status'))->toBe('current');

    // No hand-set chunk flag anywhere in Doc 10's code: the only writer of ai_usable is the one door, fed by the category.
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Domain/Kb'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            expect(preg_match('/kb_chunks[^;]*ai_usable\s*=>/s', (string) file_get_contents($file->getPathname())))->toBe(0, basename($file->getPathname()).' sets the chunk flag by hand');
        }
    }
});
