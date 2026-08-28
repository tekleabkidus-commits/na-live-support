<?php

declare(strict_types=1);

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Retrieval\HybridRetriever;
use App\Domain\Kb\Publish\PublishPipeline;
use App\Jobs\Kb\KbIndexMaintenanceConsumer;
use App\Support\Events\ConsumerRegistry;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-13 / T-01 / T-04 / T-05 / NFR-01 / NFR-06 / EC-04 / FS-01 / AC-03 (S10 step 6):
 * publish → the durable consumer (registered) chunks, sanitizes (inside the one door),
 * embeds, writes generation N+1 non-retrievable, swaps in ONE statement, purges, emits
 * kb.retrieval.updated; the chatbot then serves the new version with its version pin;
 * a re-publish re-embeds ONLY changed chunks (§12 cost); embeddings DOWN → no swap,
 * index pending, the previous generation keeps serving, retry drains (FS-01); the
 * consumer is idempotent on the event; two variants publish independently (EC-04).
 * This is the [D10] close of Doc 09 FR-10 (BS:57) and T-05 (BS:88).
 */
function pipelineDriver(int $vectors, bool $down = false): FakeDriver
{
    $driver = new FakeDriver(new FixturesOnlyEgress);
    for ($i = 0; $i < $vectors; $i++) {
        $v = array_fill(0, 1024, 0.0);
        $v[$i % 1000 + 10] = 1.0;
        if (! $down) {
            $driver->queue(new ProviderResponse('', 0, 0, [], [$v]));
        }
    }
    app(ProviderRegistry::class)->swap('fake-embed', $driver);
    config(['ai.default_routing.embeddings' => [['provider' => 'fake-embed', 'model' => 'e']]]);

    return $driver;
}

function runPublishConsumer(string $variantId): string
{
    // The relay's EFFECT for THIS variant's newest publish (the relayOutboxRow helper's
    // shape, keyed by variant so EC-04's two publishes drive their own consumers).
    /** @var object{id: string, tenant_id: string, name: string, payload: string, schema_version?: int}|null $row */
    $row = DB::table('outbox')->where('name', 'kb.article.published')->where('payload->variant_id', $variantId)->orderByDesc('id')->first();
    if ($row === null) {
        throw new RuntimeException('no kb.article.published outbox row for the variant');
    }
    DB::table('events')->insertOrIgnore(['id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'schema_version' => $row->schema_version ?? 1, 'payload' => $row->payload]);
    (new KbIndexMaintenanceConsumer($row->id))->handle(app(PublishPipeline::class));

    return $row->id;
}

it('ADR 0011: the consumer is REGISTERED for kb.article.published on the ai-background family', function (): void {
    expect(ConsumerRegistry::consumersFor('kb.article.published'))->toBe([KbIndexMaintenanceConsumer::class]);
});

it('FR-13/T-05: publish → consumer → chunks (summary = chunk 0, heading paths), embedded, swapped in one statement, index current, kb.retrieval.updated emitted; the chatbot serves the new version with its version pin; unpublish retires', function (): void {
    $s = kbScene();
    pipelineDriver(3);
    $a = kbArticle($s, '[FIXTURE] Deposits guide');
    $url = 'https://localhost/admin/kb/variants/'.$a['variant_id'];
    $this->actingAs($s['admin'], 'staff')->patchJson($url.'/meta', ['summary' => '[FIXTURE] How telebirr deposits work.'])->assertOk();
    $this->actingAs($s['admin'], 'staff')->putJson($url.'/working-copy', ['content' => ['blocks' => [['type' => 'heading', 'level' => 2, 'text' => 'Deposits'], ['type' => 'paragraph', 'text' => '[FIXTURE] Deposits with telebirr settle within 30 minutes.']]]])->assertOk();
    $published = $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish');
    $published->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('index_status'))->toBe('pending', 'humans live immediately; retrieval on swap');

    runPublishConsumer($a['variant_id']);

    $rows = DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->orderBy('chunk_index')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->content)->toBe('[FIXTURE] How telebirr deposits work.')
        ->and($rows[1]->heading_path)->toBe('[FIXTURE] Deposits guide > Deposits')
        ->and($rows->every(fn ($r) => (bool) $r->retrievable && (int) $r->retrieval_generation === 1 && $r->version_id === $published->json('version_id')))->toBeTrue();
    expect(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('index_status'))->toBe('current')
        ->and(DB::table('outbox')->where('name', 'kb.retrieval.updated')->where('payload->variant_id', $a['variant_id'])->exists())->toBeTrue();

    pipelineDriver(1);
    $served = app(HybridRetriever::class)->retrieve('[FIXTURE] telebirr deposits', ['en']);
    expect($served['chunks'][0]['version_id'])->toBe($published->json('version_id'))->and($served['chunks'][0]['article_ref'])->toBe($a['article_id']);

    $this->actingAs($s['admin'], 'staff')->postJson($url.'/unpublish')->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->where('retrievable', true)->count())->toBe(0)
        ->and(DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->count())->toBe(2, 'retired, never deleted');
});

it('§12 cost: a re-publish with one changed section re-embeds ONLY the changed chunk (the rest re-use their vectors); generation 2 serves, generation 1 retired', function (): void {
    $s = kbScene();
    $driver = pipelineDriver(6);
    $a = kbArticle($s, '[FIXTURE] Payments');
    $url = 'https://localhost/admin/kb/variants/'.$a['variant_id'];
    $doc = fn (string $limits) => ['content' => ['blocks' => [['type' => 'heading', 'level' => 2, 'text' => 'Deposits'], ['type' => 'paragraph', 'text' => '[FIXTURE] Deposits settle within 30 minutes.'], ['type' => 'heading', 'level' => 3, 'text' => 'Limits'], ['type' => 'paragraph', 'text' => $limits]]]];
    $this->actingAs($s['admin'], 'staff')->putJson($url.'/working-copy', $doc('[FIXTURE] Minimum deposit is 50 ETB.'))->assertOk();
    $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish')->assertOk();
    bindTenant($s['tenant']);
    runPublishConsumer($a['variant_id']);
    $callsAfterFirst = count($driver->seen);
    expect($callsAfterFirst)->toBe(3, 'three chunks embedded on the first publish');

    $this->actingAs($s['admin'], 'staff')->putJson($url.'/working-copy', $doc('[FIXTURE] Minimum deposit is 100 ETB.'))->assertOk();
    $v2 = $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish');
    $v2->assertOk();
    bindTenant($s['tenant']);
    runPublishConsumer($a['variant_id']);
    expect(count($driver->seen) - $callsAfterFirst)->toBe(1, 'only the changed Limits chunk re-embedded');
    $states = DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->orderBy('retrieval_generation')->orderBy('chunk_index')->get(['retrieval_generation', 'retrievable', 'version_id'])->map(fn ($r) => [(int) $r->retrieval_generation, (bool) $r->retrievable])->all();
    expect($states)->toBe([[1, false], [1, false], [1, false], [2, true], [2, true], [2, true]]);
});

it('FS-01: embeddings DOWN → generation N+1 lands but is NOT swapped, index stays pending, the previous generation keeps serving, the consumer throws (retry/re-drive); on recovery the retry swaps', function (): void {
    $s = kbScene();
    pipelineDriver(3);
    $a = kbArticle($s, '[FIXTURE] Withdrawals');
    $url = 'https://localhost/admin/kb/variants/'.$a['variant_id'];
    $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish')->assertOk();
    bindTenant($s['tenant']);
    runPublishConsumer($a['variant_id']);
    expect(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('retrieval_generation'))->toBe(1);

    pipelineDriver(0, down: true); // the provider answers nothing → embeddings fail
    $this->actingAs($s['admin'], 'staff')->putJson($url.'/working-copy', ['content' => ['blocks' => [['type' => 'paragraph', 'text' => '[FIXTURE] Withdrawals now arrive within two hours.']]]])->assertOk();
    $v2 = $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish');
    $v2->assertOk();
    bindTenant($s['tenant']);
    try {
        runPublishConsumer($a['variant_id']);
        $this->fail('the pipeline swapped without vectors');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('not swapped');
    }
    expect(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('index_status'))->toBe('pending')
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('retrieval_generation'))->toBe(1)
        ->and(DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->where('retrievable', true)->pluck('retrieval_generation')->unique()->all())->toBe([1], 'generation 1 keeps serving — never a knowledge hole')
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('status'))->toBe('published', 'humans still see it');

    pipelineDriver(3); // recovery: the retry embeds and swaps
    runPublishConsumer($a['variant_id']);
    expect(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('index_status'))->toBe('current')
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('retrieval_generation'))->toBe(2);
});

it('AC-03 stage-kill chaos + idempotency: a kill after embed leaves humans published and retrieval on generation 1 ONLY (never a mix); the re-driven consumer completes the swap; a second delivery is a no-op', function (): void {
    $s = kbScene();
    pipelineDriver(9);
    $a = kbArticle($s, '[FIXTURE] Bonus');
    $url = 'https://localhost/admin/kb/variants/'.$a['variant_id'];
    $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish')->assertOk();
    bindTenant($s['tenant']);
    runPublishConsumer($a['variant_id']);
    $this->actingAs($s['admin'], 'staff')->putJson($url.'/working-copy', ['content' => ['blocks' => [['type' => 'paragraph', 'text' => '[FIXTURE] Bonus wagering must complete within 7 days.']]]])->assertOk();
    $this->actingAs($s['admin'], 'staff')->postJson($url.'/publish')->assertOk();
    bindTenant($s['tenant']);

    foreach (['chunk', 'embed'] as $stage) {
        PublishPipeline::$killAfterStage = $stage;
        try {
            runPublishConsumer($a['variant_id']);
            $this->fail('no kill');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain('stage killed after '.$stage);
        }
        $serving = DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->where('retrievable', true)->pluck('retrieval_generation')->unique()->all();
        expect($serving)->toBe([1], "after a kill at {$stage}: retrieval serves generation 1 only — never a mix")
            ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('status'))->toBe('published');
    }
    $eventId = runPublishConsumer($a['variant_id']); // the re-drive
    expect(DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->where('retrievable', true)->pluck('retrieval_generation')->unique()->all())->toBe([2]);
    $before = DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->count();
    (new KbIndexMaintenanceConsumer($eventId))->handle(app(PublishPipeline::class)); // second delivery
    expect(DB::table('kb_chunks')->where('variant_id', $a['variant_id'])->count())->toBe($before)
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('retrieval_generation'))->toBe(2);
});

it('EC-04: two variants (en + am) publish independently — one provider failure leaves the other current and the failed one pending', function (): void {
    $s = kbScene();
    pipelineDriver(2);
    $a = kbArticle($s, '[FIXTURE] Verification');
    $am = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/variants', ['language' => 'am', 'title' => 'የማረጋገጫ መመሪያ']);
    $am->assertStatus(201);
    bindTenant($s['tenant']);
    $this->actingAs($s['admin'], 'staff')->putJson('https://localhost/admin/kb/variants/'.$am->json('variant_id').'/working-copy', ['content' => ['blocks' => [['type' => 'paragraph', 'text' => '[FIXTURE] መለያዎን ለማረጋገጥ መታወቂያ ያስፈልጋል።']]]])->assertOk();
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$am->json('variant_id').'/publish')->assertOk();
    bindTenant($s['tenant']);
    runPublishConsumer($a['variant_id']);
    pipelineDriver(0, down: true);
    try {
        runPublishConsumer($am->json('variant_id'));
    } catch (RuntimeException) {
    }
    expect(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('index_status'))->toBe('current')
        ->and(DB::table('kb_article_variants')->where('id', $am->json('variant_id'))->value('index_status'))->toBe('pending')
        ->and(DB::table('kb_article_variants')->where('id', $am->json('variant_id'))->value('status'))->toBe('published');
});
