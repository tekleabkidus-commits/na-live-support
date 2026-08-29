<?php

declare(strict_types=1);

use App\Domain\Ai\Retrieval\KbIndexer;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';
require_once __DIR__.'/../Ai/ChatbotTurnTest.php';

/**
 * S10 step 7b packet P2 (Doc 10 FR-12/FR-14/T-06): a chatbot answer citing a REAL Doc 10
 * article renders the VERSION-PINNED URL and emits kb.article.cited_by_ai (identifiers
 * only) beside the decision; a ref with NO article row keeps the bare ref (the golden
 * suite's control); a handoff with no citation emits NOTHING (the negative control).
 */
it('P2: a real article cites as 📄 /kb/cite/{article}/{version} + kb.article.cited_by_ai; a fixture ref stays bare; no citation → no event', function (): void {
    [$tenantId, $customerId, $conversation] = turnTenant();
    activateTenant(App\Models\Tenant::query()->findOrFail($tenantId));
    $admin = kbActor(['kb.categories.manage', 'kb.articles.create', 'kb.articles.edit', 'kb.articles.publish'], 'admin');
    $category = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => '[FIXTURE] Payments'], 'slug' => 'payments', 'ai_usable' => true]);
    bindTenant($tenantId);
    $s = ['tenant' => $tenantId, 'admin' => $admin, 'category_id' => $category->json('id')];
    $a = kbArticle($s, '[FIXTURE] Deposits');
    $published = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish');
    $published->assertOk();
    bindTenant($tenantId);

    app(KbIndexer::class)->index($a['article_id'], 0, 'en', '[FIXTURE] Deposits with telebirr settle within 30 minutes.', fixtureVector: unitVector(1), variantId: $a['variant_id'], versionId: $published->json('version_id'), generation: 1);
    turnDriver([], new App\Domain\Ai\Providers\ProviderResponse('', 0, 0, [], [unitVector(1)]), modelAction('answer', ['text' => '[FIXTURE] Deposits settle within 30 minutes.', 'citations' => [$a['article_id']]]));
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] how long does a telebirr deposit take?');

    $out = outboundBodies($conversation);
    $expectedUrl = '/kb/cite/'.$a['article_id'].'/'.$published->json('version_id');
    expect($out[0])->toContain('📄 '.$expectedUrl)->not->toContain('📄 '.$a['article_id']."\n");
    $event = DB::table('outbox')->where('name', 'kb.article.cited_by_ai')->first();
    expect($event)->not->toBeNull();
    $payload = json_decode((string) $event->payload, true);
    expect($payload['article_id'])->toBe($a['article_id'])->and($payload['version_id'])->toBe($published->json('version_id'))
        ->and($payload['conversation_id'])->toBe($conversation->identifier())->and($payload)->toHaveKeys(['tenant_id', 'decision_id']);

    // The control: a ref with no article row renders bare (the golden fixtures' shape).
    app(KbIndexer::class)->index(KB_DEP, 0, 'en', '[FIXTURE] Cashout rules.', fixtureVector: unitVector(2));
    turnDriver([], new App\Domain\Ai\Providers\ProviderResponse('', 0, 0, [], [unitVector(2)]), modelAction('answer', ['text' => '[FIXTURE] Cashout is available.', 'citations' => [KB_DEP]]));
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] can I cash out?');
    $out = outboundBodies($conversation);
    expect(end($out))->toContain('📄 '.KB_DEP);
    expect(DB::table('outbox')->where('name', 'kb.article.cited_by_ai')->count())->toBe(1, 'a fixture ref mints no event');

    // The negative control: a handoff (no citation) emits nothing new.
    turnDriver([], new App\Domain\Ai\Providers\ProviderResponse('', 0, 0, [], [unitVector(9)]), modelAction('handoff', ['reason' => 'customer_request']));
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] give me a human please');
    expect(DB::table('outbox')->where('name', 'kb.article.cited_by_ai')->count())->toBe(1);
});

/**
 * S10 packet P6 (countersigned at the batch-C checkpoint): CopilotPanel's kb items come from the
 * FR-17 BLEND — retrieval chunks first, keyword-only articles fill to three (an INTERNAL published
 * article among them: the Copilot's FR-10 internal-allowed posture holds on the keyword half too).
 * The chatbot path is untouched (static; the full structural proof stays KbIndexSeparationTest).
 */
it('P6: the Copilot kb card fills to three — retrieval first, keyword-only articles second and THIRD (one internal — FR-10 holds); the chatbot path never touches the blend', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] how do withdrawals work?', 'en', ['intent' => 'withdrawals']);
    $admin = kbActor(['kb.categories.manage', 'kb.articles.create', 'kb.articles.edit', 'kb.articles.publish', 'kb.view_internal'], 'kbadmin');
    $category = test()->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => '[FIXTURE] Payments'], 'slug' => 'payments-p6', 'ai_usable' => true]);
    bindTenant($tenantId);
    $s = ['tenant' => $tenantId, 'admin' => $admin, 'category_id' => $category->json('id')];

    $refs = [];
    foreach ([['[FIXTURE] Withdrawal limits', 'public'], ['[FIXTURE] Withdrawal timing', 'public'], ['[FIXTURE] Withdrawal internal playbook', 'internal']] as [$title, $audience]) {
        $a = kbArticle($s, $title, $audience);
        test()->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
        bindTenant($tenantId);
        // The keyword index learns all three (inline: the durable indexer's delivery, StaffSearchTest's shape).
        $row = DB::table('outbox')->where('name', 'kb.article.published')->where('payload->variant_id', $a['variant_id'])->orderByDesc('id')->first();
        DB::table('events')->insertOrIgnore(['id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'schema_version' => $row->schema_version ?? 1, 'payload' => $row->payload]);
        (new App\Jobs\Kb\KbSearchIndexer($row->id))->handle(app(App\Domain\Kb\Search\KbSearchDocuments::class));
        $refs[] = $a['article_id'];
    }
    // Retrieval knows ONLY the first article (one chunk, fixture vector) — keyword must fill slots 2 and 3.
    app(KbIndexer::class)->index($refs[0], 0, 'en', '[FIXTURE] Withdrawal limits are 50,000 birr per day.', fixtureVector: copilotVector(6));

    copilotDriver(
        new App\Domain\Ai\Providers\ProviderResponse('[FIXTURE] Customer asks about withdrawals.', 20, 10),
        copilotEmbedding(6),
        copilotEmbedding(6),
    );
    bindTenant($tenant);
    $payload = app(App\Domain\Ai\Copilot\CopilotPanel::class)->refresh($actor, $conversation);

    $kbRefs = array_column($payload['kb'], 'article_ref');
    expect($payload['kb'])->toHaveCount(3)
        ->and($kbRefs[0])->toBe($refs[0], 'the retrieval chunk stays first')
        ->and(array_slice($kbRefs, 1))->each->toBeIn(array_slice($refs, 1)) // slots 2+3 are the keyword-only articles
        ->and($kbRefs)->toContain($refs[2]) // the INTERNAL published article is reachable on the keyword half — FR-10
        ->and(array_unique($kbRefs))->toHaveCount(3);

    // The chatbot path never touches the blend (the structural separation proof is KbIndexSeparationTest;
    // this pins the wiring boundary at the source).
    expect((string) file_get_contents(app_path('Domain/Ai/Chatbot/ChatbotTurn.php')))->not->toContain('CopilotKbBlend');
});
