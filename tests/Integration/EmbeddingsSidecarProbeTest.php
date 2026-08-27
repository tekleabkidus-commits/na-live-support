<?php

declare(strict_types=1);

use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Retrieval\HybridRetriever;
use App\Domain\Ai\Retrieval\KbIndexer;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| ADR 0018 — the LIVE sidecar probe (S10 step 0.7, STOP-C ruling option 2)
|--------------------------------------------------------------------------
|
| "the sidecar itself is proven by its own integration probe (real text →
| 1024-dim vector, cosine sanity)". LIVE-GATED: AI_EMBEDDINGS_LIVE=1 runs it
| against the real `embeddings` container (compose profile `embeddings`);
| otherwise it SKIPS — CI stays on the deterministic fake driver (the ruling).
| Every input is [FIXTURE]-tagged: the egress guard admits it by mechanism.
| The second case is the ruling's "retrieval suites against the REAL sidecar":
| index → retrieve through the built Doc 09 seam with NO fixture vector.
*/

function cosine(array $a, array $b): float
{
    $dot = 0.0;
    $na = 0.0;
    $nb = 0.0;
    foreach ($a as $i => $x) {
        $dot += $x * $b[$i];
        $na += $x * $x;
        $nb += $b[$i] * $b[$i];
    }

    return $dot / (sqrt($na) * sqrt($nb));
}

beforeEach(function (): void {
    if (env('AI_EMBEDDINGS_LIVE') !== '1') {
        $this->markTestSkipped('AI_EMBEDDINGS_LIVE=1 runs this against the real ADR 0018 sidecar (compose profile `embeddings`); CI stays on the fake driver.');
    }
});

it('ADR 0018 probe: real text → 1024-dim unit vectors; a cross-language paraphrase sits closer than an unrelated sentence (bge-m3 cosine sanity)', function (): void {
    $driver = app(ProviderRegistry::class)->driver('local-embeddings', 120);
    $response = $driver->embeddings(['model' => 'bge-m3', 'input' => [
        '[FIXTURE] Deposits with telebirr settle within 30 minutes.',
        '[FIXTURE] በቴሌብር የሚደረግ ተቀማጭ በ30 ደቂቃ ውስጥ ይደርሳል።',
        '[FIXTURE] Cashout is available on accumulators before the last leg starts.',
    ]]);

    expect($response->vectors)->toHaveCount(3);
    foreach ($response->vectors as $vector) {
        expect($vector)->toHaveCount(1024);
        expect(abs(cosine($vector, $vector) - 1.0))->toBeLessThan(1e-6);
    }
    $paraphrase = cosine($response->vectors[0], $response->vectors[1]);
    $unrelated = cosine($response->vectors[0], $response->vectors[2]);
    fwrite(STDERR, sprintf("\ncosine(en deposit, am deposit)=%.4f  cosine(en deposit, cashout)=%.4f  tokens_in=%d\n", $paraphrase, $unrelated, $response->tokensIn));
    expect($paraphrase)->toBeGreaterThan($unrelated)
        ->and($response->tokensIn)->toBeGreaterThan(0);
});

it('the retrieval seam against the REAL sidecar: KbIndexer embeds with no fixture vector, HybridRetriever answers un-degraded with the semantically nearest chunk first', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $indexer = app(KbIndexer::class);
    $dep = '018f6b2e-0000-7000-8000-00000000e0a1';
    $cash = '018f6b2e-0000-7000-8000-00000000e0b2';
    $indexer->index($dep, 0, 'en', '[FIXTURE] Deposits with telebirr settle within 30 minutes.');
    $indexer->index($cash, 0, 'en', '[FIXTURE] Cashout is available on accumulators before the last leg starts.');

    expect(DB::table('kb_chunks')->whereIn('article_ref', [$dep, $cash])->whereNull('embedding')->count())->toBe(0, 'every chunk got a real vector');

    $result = app(HybridRetriever::class)->retrieve('[FIXTURE] how fast does a telebirr top-up arrive', ['en']);
    expect($result['degraded'])->toBeFalse()
        ->and($result['chunks'][0]['article_ref'])->toBe($dep);
});
