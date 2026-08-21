<?php

declare(strict_types=1);

use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Ai\Pipeline\InboundClassification;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Retrieval\HybridRetriever;
use App\Domain\Ai\Retrieval\KbIndexer;
use App\Domain\Ai\Simulation\SimulationEngine;
use App\Jobs\Ai\ClassifyInboundMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 09 §12 / NFR-01 / NFR-02 / NFR-03 / NFR-05 — the AI budget suite (S09 step 14)
|--------------------------------------------------------------------------
|
| DEV-HARDWARE-INDICATIVE, the Doc 01–08 pattern: the default run asserts a 5×
| regression ceiling; PERF_BUDGETS_ENFORCE=1 (`make perf`) asserts the spec
| numbers. Real numbers print on every run — the honest record.
|
| WHAT IS MEASURED HERE vs BUCKETED [D19] (the pre-agreed split):
|  - NFR-05 decision-write overhead ≤ 3 ms per record (the async path's staging
|    + batch dispatch; the INSERT itself rides the job) — measured in-suite.
|  - NFR-02 classification pipeline OVERHEAD (persist-first → consumer → decision
|    row) with a scripted provider: the provider's own latency is excluded by
|    construction — the ≤ 1.2 s p95 spec bound contains BOTH; Addis AI's measured
|    p50 ≈ 2.4 s at STEP 0.2 is the standing §9 risk row, so the spec figure is
|    NOT claimed here; the pipeline's own cost is.
|  - §12 "retrieval ≤ 120 ms p95 at 50k chunks": hybrid retrieval at IN-SUITE scale
|    (2k chunks); the 50k profile is Doc 19 load work.
|  - §12 "simulation over one day's decisions (10k) ≤ 5 s": in-suite 2k decisions;
|    the linearity of the engine (a single indexed cursor) is what the number shows.
|  - NFR-01 first-token / NFR-03 in-panel first-token: PROVIDER-BOUND (streaming
|    landed at step 10; the nightly live leg observes real tokens) — [D19]/[OA],
|    not measurable against a fake provider without lying.
*/

function perfAiTenant(): array
{
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    Illuminate\Support\Facades\Cache::flush();
    registerFakeChannel();

    return [$tenant, $tenant->identifier()];
}

test('NFR-05: staging + flushing one decision record costs ≤ 3 ms p99 (the async batch path)', function () {
    [, $tenantId] = perfAiTenant();
    $writer = app(DecisionWriter::class);

    $stats = measure(function () use ($writer): void {
        $writer->record(decisionFixture(['responsePayload' => ['classification' => ['intent' => 'deposits']]]));
        $writer->flush();
    }, samples: 300);

    reportBudget('NFR-05 decision write', 3.0, $stats);
    assertBudget('NFR-05 decision write', $stats, 3.0);
});

test('§12 retrieval: hybrid (vector + keyword) top-5 over 2k fixture chunks ≤ 120 ms p95 — in-suite scale; 50k is Doc 19', function () {
    [, $tenantId] = perfAiTenant();
    $indexer = app(KbIndexer::class);
    for ($i = 0; $i < 2000; $i++) {
        $v = array_fill(0, 1024, 0.0);
        $v[$i % 1024] = 1.0;
        $v[($i * 7) % 1024] = 0.5;
        $indexer->index('018f6b2e-0000-7000-8000-'.str_pad(dechex($i), 12, '0', STR_PAD_LEFT), 0, $i % 3 === 0 ? 'am' : 'en', 'Fixture article '.$i.' about deposits withdrawals bonuses and verification timing.', fixtureVector: $v);
    }
    $driver = new App\Domain\Ai\Providers\FakeDriver(new App\Domain\Ai\Guards\FixturesOnlyEgress);
    app(App\Domain\Ai\Providers\ProviderRegistry::class)->swap('fake-perf', $driver);
    config(['ai.default_routing.embeddings' => [['provider' => 'fake-perf', 'model' => 'e']]]);
    $query = array_fill(0, 1024, 0.0);
    $query[42] = 1.0;

    $retriever = app(HybridRetriever::class);
    $stats = measure(function () use ($retriever, $driver, $query): void {
        $driver->queue(new ProviderResponse('', 0, 0, [], [$query]));
        $retriever->retrieve('[FIXTURE] deposits timing', ['en', 'am']);
    }, samples: 200);

    reportBudget('§12 retrieval p95-class (2k chunks)', 120.0, $stats);
    assertBudget('§12 retrieval (2k chunks)', $stats, 120.0);
});

test('§12 simulation: one day of 2k classification decisions recomputes ≤ 5 s (in-suite; 10k is Doc 19 — the engine is one indexed cursor)', function () {
    [, $tenantId] = perfAiTenant();
    $writer = app(DecisionWriter::class);
    for ($i = 0; $i < 2000; $i++) {
        $writer->record(decisionFixture(['confidence' => ($i % 100) / 100, 'rgRisk' => ($i % 37) / 37, 'responsePayload' => ['classification' => ['intent' => 'deposits', 'spam' => ($i % 11) / 11]]]));
        if ($i % 200 === 199) {
            $writer->flush();
        }
    }
    $writer->flush();
    $day = DB::selectOne("SELECT date_trunc('day', clock_timestamp())::text AS d")->d;

    $engine = app(SimulationEngine::class);
    $stats = measure(fn () => $engine->simulateDay($day, ['rg_min' => 0.8, 'spam_min' => 0.9, 'handoff_below' => 0.3, 'auto_resolve_min' => 0.85]), samples: 10);

    reportBudget('§12 simulation (2k decisions)', 5000.0, $stats);
    assertBudget('§12 simulation (2k decisions)', $stats, 5000.0);
});

test('NFR-02 pipeline OVERHEAD: persist-first → consumer → classification row with a scripted provider ≤ 1.2 s p95 (the provider\'s own latency is the §9 risk row, excluded here)', function () {
    [$tenant, $tenantId] = perfAiTenant();
    $configs = app(App\Domain\Ai\Config\ConfigVersions::class);
    $v = $configs->draft('thresholds', 'default', ['chatbot_enabled' => false, 'rg_min' => 0.8, 'handoff_below' => 0.3, 'auto_resolve_min' => 0.85, 'spam_min' => 0.9], (string) Str::uuid7());
    $configs->publish($v, (string) Str::uuid7());
    $customerId = (string) Str::uuid7();
    DB::table('customers')->insert(['id' => $customerId, 'tenant_id' => $tenantId, 'created_source' => 'telegram', 'language' => 'en']);
    $conversation = anotherConversation($tenantId, 'perf', $customerId);
    $driver = new App\Domain\Ai\Providers\FakeDriver(new App\Domain\Ai\Guards\FixturesOnlyEgress);
    app(App\Domain\Ai\Providers\ProviderRegistry::class)->swap('fake-perf', $driver);
    config(['ai.default_routing.fast' => [['provider' => 'fake-perf', 'model' => 'm']], 'ai.default_routing.strong' => [['provider' => 'fake-perf', 'model' => 'm']]]);
    $classification = new ProviderResponse((string) json_encode(['intent' => 'deposits', 'sentiment' => 0, 'urgency' => 1, 'spam' => 0.0, 'rg_risk' => 0.05, 'confidence' => 0.8]), 100, 20);

    $stats = measure(function () use ($driver, $classification, $tenantId, $conversation, $customerId): void {
        $driver->queue($classification);
        $id = (string) Str::uuid7();
        DB::table('messages')->insert(['id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customerId, 'kind' => 'text', 'body' => '[FIXTURE] deposit question '.$id, 'language' => 'en']);
        $eventId = (string) Str::uuid7();
        DB::table('events')->insert(['id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'message.received', 'payload' => json_encode(['tenant_id' => $tenantId, 'message_id' => $id, 'conversation_id' => $conversation->identifier(), 'customer_id' => $customerId, 'kind' => 'text'])]);
        (new ClassifyInboundMessage($eventId))->handle(app(InboundClassification::class));
    }, samples: 60);

    reportBudget('NFR-02 classification pipeline overhead', 1200.0, $stats);
    assertBudget('NFR-02 classification pipeline overhead', $stats, 1200.0);
});
