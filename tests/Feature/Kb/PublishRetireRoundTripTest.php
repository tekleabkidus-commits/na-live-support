<?php

declare(strict_types=1);

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Retrieval\HybridRetriever;
use App\Domain\Ai\Retrieval\KbIndexer;
use App\Domain\Kb\Tombstones\ChunkStateCitationResolver;
use App\Domain\Kb\Tombstones\CitationResolver;
use App\Domain\Kb\Tombstones\CitationState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Doc 10 FR-13/FR-16/EC-06/EC-07 — the PUBLISH → RETIRE ROUND TRIP over the
 * EXISTING Doc 09 door with fixture vectors (S10 step 1, proven BEFORE live
 * index maintenance wires in at step 6): index → the chatbot retriever serves
 * it → the citation resolves Current → retire → no longer served → the row
 * SURVIVES (the flip, never a delete) → the citation resolves Retired, never a
 * 404 → an app-role DELETE is REFUSED by grant physics (the positive control
 * for sabotage #2) → a never-indexed ref resolves Unknown.
 */
const RT_REF = '018f6b2e-0000-7000-8000-0000000002a1';
const RT_OTHER = '018f6b2e-0000-7000-8000-0000000002b2';

function rtVector(int $hot): array
{
    $v = array_fill(0, 1024, 0.0);
    $v[$hot] = 1.0;

    return $v;
}

function rtQueryDriver(array ...$vectors): void
{
    $driver = new FakeDriver(new FixturesOnlyEgress);
    foreach ($vectors as $vector) {
        $driver->queue(new ProviderResponse('', 0, 0, [], [$vector]));
    }
    app(ProviderRegistry::class)->swap('fake-embed', $driver);
    config(['ai.default_routing.embeddings' => [['provider' => 'fake-embed', 'model' => 'e']]]);
}

it('binds the resolver contract behind ONE interface (step 1 bound the chunk-state implementation; step 7 rebound the truth-fed one — the contract never moved)', function (): void {
    bindTenant(createTenant());
    expect(app(CitationResolver::class))->toBeInstanceOf(App\Domain\Kb\Tombstones\VersionedCitationResolver::class)
        ->and((new ChunkStateCitationResolver)->resolve('018f6b2e-0000-7000-8000-00000000ffff'))->toBe(CitationState::Unknown);
});

it('round trip: index → served + Current → retire → not served, row survives, Retired (never 404) → app DELETE refused → unknown ref resolves Unknown', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    rtQueryDriver(rtVector(5), rtVector(5));
    $indexer = app(KbIndexer::class);
    $resolver = app(CitationResolver::class);

    $indexer->index(RT_REF, 0, 'en', '[FIXTURE] Bonus wagering must be completed within 7 days of activation.', fixtureVector: rtVector(5));
    $indexer->index(RT_OTHER, 0, 'en', '[FIXTURE] Cashout is available on accumulators before the last leg starts.', fixtureVector: rtVector(9));

    $served = array_column(app(HybridRetriever::class)->retrieve('[FIXTURE] bonus wagering deadline', ['en'])['chunks'], 'article_ref');
    expect($served[0] ?? null)->toBe(RT_REF)
        ->and($resolver->resolve(RT_REF))->toBe(CitationState::Current);

    expect($indexer->retire(RT_REF))->toBe(1);

    $after = array_column(app(HybridRetriever::class)->retrieve('[FIXTURE] bonus wagering deadline', ['en'])['chunks'], 'article_ref');
    expect($after)->not->toContain(RT_REF)
        ->and(DB::table('kb_chunks')->where('article_ref', RT_REF)->count())->toBe(1, 'retire must FLIP, never delete')
        ->and(DB::table('kb_chunks')->where('article_ref', RT_REF)->value('retrievable'))->toBeFalse()
        ->and($resolver->resolve(RT_REF))->toBe(CitationState::Retired);

    // Positive control (sabotage #2's predicted red): the app role cannot DELETE.
    // Savepoint-wrapped so the refusal (42501) does not abort the test's own transaction.
    try {
        DB::transaction(static fn () => DB::table('kb_chunks')->where('article_ref', RT_REF)->delete());
        $this->fail('the app role deleted a kb_chunks row — REVOKE DELETE is gone');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('permission denied');
    }
    expect(DB::table('kb_chunks')->where('article_ref', RT_REF)->count())->toBe(1);

    expect($resolver->resolve('018f6b2e-0000-7000-8000-00000000ffff'))->toBe(CitationState::Unknown);
});
