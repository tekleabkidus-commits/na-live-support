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
