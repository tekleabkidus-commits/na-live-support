<?php

declare(strict_types=1);

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Retrieval\HybridRetriever;
use App\Domain\Ai\Retrieval\KbIndexer;
use App\Support\Tenancy\ResolutionSource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Doc 10 T-01 / NFR-06 / FR-14 (S10 step 2b — the swap DOOR and the prune, proven
 * before the publish pipeline consumes them at step 6): a new generation lands
 * NON-retrievable beside the serving one; the swap is ONE UPDATE statement after
 * which exactly the new generation serves (never a mix); the retriever carries
 * the version pin + heading path; the superseded generation is removed ONLY by
 * the platform-role prune (STOP-A condition 5 — the app cannot delete), and only
 * once it is two generations behind and not the current version.
 */
function swapVector(int $hot): array
{
    $v = array_fill(0, 1024, 0.0);
    $v[$hot] = 1.0;

    return $v;
}

function swapScene(): array
{
    $tenant = createTenant();
    $tenantId = bindTenant($tenant, ResolutionSource::Console);
    $driver = new FakeDriver(new FixturesOnlyEgress);
    foreach ([1, 1, 1, 1] as $hot) {
        $driver->queue(new ProviderResponse('', 0, 0, [], [swapVector($hot)]));
    }
    app(ProviderRegistry::class)->swap('fake-embed', $driver);
    config(['ai.default_routing.embeddings' => [['provider' => 'fake-embed', 'model' => 'e']]]);

    $category = (string) new UuidV7;
    $article = (string) new UuidV7;
    $variant = (string) new UuidV7;
    DB::table('kb_categories')->insert(['id' => $category, 'tenant_id' => $tenantId, 'depth' => 1, 'name' => json_encode(['en' => '[FIXTURE] Payments']), 'slug' => 'payments', 'ai_usable' => true]);
    DB::table('kb_articles')->insert(['id' => $article, 'tenant_id' => $tenantId, 'category_id' => $category, 'audience' => 'public', 'source_language' => 'en', 'created_by' => (string) new UuidV7]);
    DB::table('kb_article_variants')->insert(['id' => $variant, 'tenant_id' => $tenantId, 'article_id' => $article, 'language' => 'en', 'status' => 'published', 'slug' => 'deposits', 'retrieval_generation' => 1]);

    return [$tenant, $tenantId, $article, $variant];
}

it('the swap is ONE statement: generation 2 lands non-retrievable beside generation 1, then exactly generation 2 serves — the retriever carries version_id + heading_path', function (): void {
    [$tenant, $tenantId, $article, $variant] = swapScene();
    $indexer = app(KbIndexer::class);
    $v1 = (string) new UuidV7;
    $v2 = (string) new UuidV7;

    $indexer->index($article, 0, 'en', '[FIXTURE] Deposits with telebirr settle within 30 minutes.', fixtureVector: swapVector(1), variantId: $variant, versionId: $v1, generation: 1, headingPath: 'Deposits');
    $indexer->index($article, 1, 'en', '[FIXTURE] Minimum deposit is 50 ETB.', fixtureVector: swapVector(1), variantId: $variant, versionId: $v1, generation: 1, headingPath: 'Deposits > Limits');
    $indexer->index($article, 0, 'en', '[FIXTURE] Deposits with telebirr settle within 10 minutes.', fixtureVector: swapVector(1), variantId: $variant, versionId: $v2, generation: 2, headingPath: 'Deposits', retrievable: false);
    $indexer->index($article, 1, 'en', '[FIXTURE] Minimum deposit is 50 ETB.', fixtureVector: swapVector(1), variantId: $variant, versionId: $v2, generation: 2, headingPath: 'Deposits > Limits', retrievable: false);

    $before = app(HybridRetriever::class)->retrieve('[FIXTURE] telebirr deposits settle', ['en']);
    expect(array_unique(array_column($before['chunks'], 'version_id')))->toBe([$v1])
        ->and($before['chunks'][0]['heading_path'])->toBe('Deposits')
        ->and($before['chunks'][0]['language'])->toBe('en');

    DB::enableQueryLog();
    $flipped = $indexer->swap($variant, 2);
    $updates = array_values(array_filter(DB::getQueryLog(), static fn (array $q): bool => str_starts_with(strtolower($q['query']), 'update') && str_contains($q['query'], 'kb_chunks')));
    DB::disableQueryLog();
    expect($flipped)->toBe(4)->and($updates)->toHaveCount(1, 'the swap must be ONE statement (NFR-06: no mixed generation is ever observable)');

    $states = DB::table('kb_chunks')->where('variant_id', $variant)->orderBy('retrieval_generation')->orderBy('chunk_index')->get(['retrieval_generation', 'retrievable'])->map(fn ($r) => [(int) $r->retrieval_generation, (bool) $r->retrievable])->all();
    expect($states)->toBe([[1, false], [1, false], [2, true], [2, true]]);
    $after = app(HybridRetriever::class)->retrieve('[FIXTURE] telebirr deposits settle', ['en']);
    expect(array_unique(array_column($after['chunks'], 'version_id')))->toBe([$v2])
        ->and($after['chunks'][0]['content'])->toContain('10 minutes');
});

it('STOP-A condition 5: only the PLATFORM-role prune removes a superseded generation (two behind, not the current version); the serving generation and the previous one survive', function (): void {
    [$tenant, $tenantId, $article, $variant] = swapScene();
    $indexer = app(KbIndexer::class);
    $versions = [1 => (string) new UuidV7, 2 => (string) new UuidV7, 3 => (string) new UuidV7];
    foreach ($versions as $generation => $versionId) {
        $indexer->index($article, 0, 'en', '[FIXTURE] Deposit rules, revision '.$generation.'.', fixtureVector: swapVector(1), variantId: $variant, versionId: $versionId, generation: $generation, retrievable: false);
    }
    $indexer->swap($variant, 3);
    DB::table('kb_article_variants')->where('id', $variant)->update(['retrieval_generation' => 3, 'current_version_id' => $versions[3]]);
    commitFixtures(); // the platform connection reads committed rows
    app(App\Support\Tenancy\TenantContext::class)->clear(); // SEC-02: the platform role never runs with a tenant bound

    Artisan::call('kb:chunks-prune');

    $remaining = asPlatformWorker(fn (): array => DB::connection('pgsql_platform')->table('kb_chunks')->where('variant_id', $variant)->orderBy('retrieval_generation')->pluck('retrieval_generation')->map(fn ($g) => (int) $g)->all());
    expect($remaining)->toBe([2, 3], 'generation 1 (two behind) is pruned; 2 and the serving 3 survive');
    expect(Artisan::output())->toContain('1 superseded chunk row(s) removed');
});
