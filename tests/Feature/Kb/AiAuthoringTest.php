<?php

declare(strict_types=1);

use App\Domain\Ai\Providers\ProviderResponse;
use App\Jobs\Kb\KbAuthoringJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-06 / BR-02 / AC-09 / US-06 / SS-03 (S10 step 9): every tool = a Doc 09 orchestrator task
 * producing an AI-ACCENTED DRAFT (`ai: true` blocks; AI-born variants `translation_source=ai`), written
 * through the editor's own doors from the `ai` queue, decision-logged and audited — and NEVER published.
 * Draft-from-conversations consumes Doc 09 FR-20's ids-only seam end-to-end against a cluster fixture:
 * ids in the request/job/decision identifiers, customer text read server-side only.
 */
function runAuthoringJob(KbAuthoringJob $job): void
{
    $job->handle(
        app(App\Domain\Ai\Orchestrator\AiOrchestrator::class), app(App\Domain\Ai\Prompt\LayeredAssembly::class), app(App\Domain\Ai\Prompt\PiiRedaction::class),
        app(App\Domain\Kb\Editor\WorkingCopy::class), app(App\Domain\Kb\Articles\ArticleWriter::class), app(App\Domain\Ai\DecisionLog\DecisionWriter::class), app(App\Support\Audit\AuditLogger::class),
    );
}

function aiDraftJson(string $title, array $blocks): string
{
    return json_encode(['title' => $title, 'summary' => '[FIXTURE] '.$title.' in one line.', 'blocks' => $blocks], JSON_UNESCAPED_UNICODE);
}

it('draft-from-source: 202 + an AI-born draft; the job writes accented blocks + title + summary, logs kb_draft_from_source, audits; status stays draft; redelivery calls no model; invented digits are refused', function (): void {
    Bus::fake([KbAuthoringJob::class]);
    $s = kbScene();
    $creator = kbActor(['kb.articles.create'], 'creator');
    $body = ['category_id' => $s['category_id'], 'audience' => 'public', 'language' => 'en', 'title' => '[FIXTURE] Bonus terms', 'text' => '[FIXTURE] Wagering requirement is 5x the bonus. Bonus expires after 30 days.'];
    $this->actingAs(kbActor(['kb.articles.edit'], 'editor'), 'staff')->postJson('https://localhost/admin/kb/drafts/from-source', $body)->assertStatus(403);
    bindTenant($s['tenant']);
    $r = $this->actingAs($creator, 'staff')->postJson('https://localhost/admin/kb/drafts/from-source', $body);
    $r->assertStatus(202);
    bindTenant($s['tenant']);
    $vid = (string) $r->json('variant_id');
    expect(DB::table('kb_article_variants')->where('id', $vid)->value('translation_source'))->toBe('ai', 'born accented')
        ->and(DB::table('audit_log')->where('action_key', 'kb.variant.ai_requested')->where('entity_id', $vid)->exists())->toBeTrue();
    Bus::assertDispatched(KbAuthoringJob::class, fn (KbAuthoringJob $j) => $j->task === 'kb_draft_from_source' && $j->variantId === $vid);

    $driver = copilotDriver(new ProviderResponse(aiDraftJson('[FIXTURE] Bonus terms explained', [['type' => 'heading', 'text' => '[FIXTURE] Wagering'], ['type' => 'paragraph', 'text' => '[FIXTURE] You must wager 5x the bonus.'], ['type' => 'list', 'text' => '[FIXTURE] Key dates', 'items' => ['[FIXTURE] Expires after 30 days']]]), 300, 120));
    runAuthoringJob(new KbAuthoringJob('kb_draft_from_source', $vid, $creator->identifier(), ['text' => $body['text']]));
    $row = DB::table('kb_article_variants')->where('id', $vid)->first(['status', 'title', 'summary', 'working_copy']);
    $blocks = json_decode((string) $row->working_copy, true)['blocks'];
    expect($row->status)->toBe('draft')->and($row->title)->toBe('[FIXTURE] Bonus terms explained')->and($row->summary)->toContain('one line')
        ->and(count($blocks))->toBe(3)->and(array_unique(array_column($blocks, 'ai')))->toBe([true], 'every produced block is AI-accented')->and($blocks[2]['items'][0])->toContain('30 days')
        ->and(DB::table('ai_decisions')->where('task', 'kb_draft_from_source')->value('action'))->toBe('draft_written')
        ->and(DB::table('audit_log')->where('action_key', 'kb.variant.ai_drafted')->where('entity_id', $vid)->exists())->toBeTrue()
        ->and($driver->seen[0]['messages'][0]['content'])->toContain('never invent, convert or round');
    runAuthoringJob(new KbAuthoringJob('kb_draft_from_source', $vid, $creator->identifier(), ['text' => $body['text']]));
    expect(count($driver->seen))->toBe(1, 'redelivery: the accented draft is already there');

    // Invented digit → refused, nothing written.
    $r2 = $this->actingAs($creator, 'staff')->postJson('https://localhost/admin/kb/drafts/from-source', $body);
    bindTenant($s['tenant']);
    $vid2 = (string) $r2->json('variant_id');
    copilotDriver(new ProviderResponse(aiDraftJson('[FIXTURE] Bonus', [['type' => 'paragraph', 'text' => '[FIXTURE] You must wager 7x the bonus.']]), 10, 10));
    runAuthoringJob(new KbAuthoringJob('kb_draft_from_source', $vid2, $creator->identifier(), ['text' => $body['text']]));
    expect(KbAuthoringJob::textBlocks(json_decode((string) DB::table('kb_article_variants')->where('id', $vid2)->value('working_copy'), true)))->toBe([])
        ->and(DB::table('ai_decisions')->where('task', 'kb_draft_from_source')->where('error_category', 'guard_refused')->count())->toBe(1);
});

it('simplify + FAQ variants: rewrite in place (every text block accented, digits kept or refused) and ONE appended "also asked" callout carrying an i18n label key; both 422 on an empty copy; status stays draft', function (): void {
    Bus::fake([KbAuthoringJob::class]);
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Deposits');
    $editor = kbActor(['kb.articles.edit'], 'editor');
    $this->actingAs($editor, 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/ai/simplify')->assertStatus(202);
    bindTenant($s['tenant']);
    Bus::assertDispatched(KbAuthoringJob::class, fn (KbAuthoringJob $j) => $j->task === 'improve_text' && $j->variantId === $a['variant_id'] && $j->params === []);

    copilotDriver(new ProviderResponse(json_encode([['id' => 'b1', 'text' => '[FIXTURE] Telebirr deposits arrive in 30 minutes.']]), 50, 20));
    runAuthoringJob(new KbAuthoringJob('improve_text', $a['variant_id'], $editor->identifier()));
    $blocks = json_decode((string) DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('working_copy'), true)['blocks'];
    expect($blocks[0]['text'])->toBe('[FIXTURE] Telebirr deposits arrive in 30 minutes.')->and($blocks[0]['ai'])->toBeTrue()
        ->and(DB::table('ai_decisions')->where('task', 'improve_text')->value('action'))->toBe('draft_written');

    $this->actingAs($editor, 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/ai/faq')->assertStatus(202);
    bindTenant($s['tenant']);
    copilotDriver(new ProviderResponse(json_encode(['[FIXTURE] How long does a telebirr deposit take?', '[FIXTURE] My deposit has not arrived yet']), 40, 30));
    runAuthoringJob(new KbAuthoringJob('kb_faq_variants', $a['variant_id'], $editor->identifier()));
    $blocks = json_decode((string) DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('working_copy'), true)['blocks'];
    expect(count($blocks))->toBe(2)->and($blocks[1]['type'])->toBe('callout')->and($blocks[1]['label_key'])->toBe('kb.blocks.also_asked')->and($blocks[1]['items'])->toHaveCount(2)->and($blocks[1]['ai'])->toBeTrue()
        ->and(__('kb.blocks.also_asked'))->toBe('Also asked')
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('status'))->toBe('draft');
    runAuthoringJob(new KbAuthoringJob('kb_faq_variants', $a['variant_id'], $editor->identifier()));
    expect(count(json_decode((string) DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('working_copy'), true)['blocks']))->toBe(2, 'redelivery appends nothing');

    // A dropped digit on simplify is refused (strict both ways).
    $b = kbArticle($s, '[FIXTURE] Limits');
    copilotDriver(new ProviderResponse(json_encode([['id' => 'b1', 'text' => '[FIXTURE] Deposits with telebirr settle quickly.']]), 50, 20));
    runAuthoringJob(new KbAuthoringJob('improve_text', $b['variant_id'], $editor->identifier()));
    expect(DB::table('ai_decisions')->where('task', 'improve_text')->where('error_category', 'guard_refused')->count())->toBe(1)
        ->and(json_decode((string) DB::table('kb_article_variants')->where('id', $b['variant_id'])->value('working_copy'), true)['blocks'][0]['text'])->toContain('30 minutes') /* refused — untouched */;

    $empty = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/articles', ['category_id' => $s['category_id'], 'audience' => 'public', 'source_language' => 'en', 'title' => '[FIXTURE] Empty']);
    bindTenant($s['tenant']);
    $this->actingAs($editor, 'staff')->postJson('https://localhost/admin/kb/variants/'.$empty->json('variant_id').'/ai/simplify')->assertStatus(422);
    bindTenant($s['tenant']);
    $this->actingAs($editor, 'staff')->postJson('https://localhost/admin/kb/variants/'.$empty->json('variant_id').'/ai/faq')->assertStatus(422);
});

it('US-06 / SS-03 / [D10] rows 2+5: the Doc 09 top-10 cluster (ids only) → one click → a draft with the questions as headings; the request, the job and the decision identifiers carry IDS, the customer text is read server-side; an unknown intent is 422', function (): void {
    Bus::fake([KbAuthoringJob::class]);
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $admin = kbActor(['kb.categories.manage', 'kb.articles.create'], 'kb-admin');
    $category = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/categories', ['name' => ['en' => '[FIXTURE] Bonuses', 'am' => 'ጉርሻዎች'], 'slug' => 'bonuses', 'ai_usable' => true]);
    $category->assertStatus(201);
    bindTenant($tenant);
    // The cluster fixture: three unanswered bonus questions this week (no_grounding handoffs on real customer messages).
    $writer = app(App\Domain\Ai\DecisionLog\DecisionWriter::class);
    $questions = ['[FIXTURE] is the bonus valid on live bets?', '[FIXTURE] why did my bonus expire?', '[FIXTURE] can I withdraw bonus winnings?'];
    $messageIds = [];
    foreach ($questions as $q) {
        $mid = (string) Str::uuid7();
        DB::table('messages')->insert(['id' => $mid, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'direction' => 'in', 'author_type' => 'customer', 'author_id' => $conversation->customer_id, 'kind' => 'text', 'body' => $q, 'language' => 'en']);
        $writer->record(decisionFixture(['surface' => 'chatbot', 'task' => 'chatbot_turn', 'action' => 'handoff', 'intent' => 'bonuses', 'conversationId' => $conversation->identifier(), 'messageId' => $mid, 'responsePayload' => ['handoff_reason' => 'no_grounding']]));
        $messageIds[] = $mid;
    }
    $writer->flush();
    expect(app(App\Domain\Ai\DecisionLog\WeeklyOutputs::class)->topUnanswerable()[0]['intent'])->toBe('bonuses', 'the seam sees the cluster');

    $body = ['intent' => 'bonuses', 'category_id' => $category->json('id'), 'audience' => 'public', 'language' => 'en'];
    $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/drafts/from-conversations', ['intent' => 'withdrawals'] + $body)->assertStatus(422);
    bindTenant($tenant);
    $r = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/kb/drafts/from-conversations', $body);
    $r->assertStatus(202);
    bindTenant($tenant);
    $vid = (string) $r->json('variant_id');
    expect($r->json('message_count'))->toBe(3)->and(json_encode($r->json()))->not->toContain('bonus valid') /* no text in the response */;
    Bus::assertDispatched(KbAuthoringJob::class, function (KbAuthoringJob $j) use ($vid, $messageIds): bool {
        return $j->task === 'kb_draft_from_conversations' && $j->variantId === $vid && $j->params['message_ids'] === $messageIds && ! str_contains(json_encode($j->params), 'bonus valid'); // IDS ONLY
    });

    $driver = copilotDriver(new ProviderResponse(aiDraftJson('[FIXTURE] Bonus questions', [['type' => 'heading', 'text' => '[FIXTURE] Is the bonus valid on live bets?'], ['type' => 'paragraph', 'text' => '[FIXTURE] Operator: describe live-bet eligibility.'], ['type' => 'heading', 'text' => '[FIXTURE] Why did my bonus expire?'], ['type' => 'paragraph', 'text' => '[FIXTURE] Operator: state the expiry rule.']]), 400, 200));
    runAuthoringJob(new KbAuthoringJob('kb_draft_from_conversations', $vid, $admin->identifier(), ['message_ids' => $messageIds, 'intent' => 'bonuses']));
    $row = DB::table('kb_article_variants')->where('id', $vid)->first(['status', 'title', 'translation_source', 'working_copy']);
    $blocks = json_decode((string) $row->working_copy, true)['blocks'];
    expect($row->status)->toBe('draft')->and($row->translation_source)->toBe('ai')->and($row->title)->toBe('[FIXTURE] Bonus questions')
        ->and(array_column(array_filter($blocks, fn ($b) => $b['type'] === 'heading'), 'text'))->toHaveCount(2)
        ->and($driver->seen[0]['messages'][1]['content'])->toContain('is the bonus valid on live bets?') /* the customer text reached the model from the SERVER read */
        ->and(DB::table('ai_decisions')->where('task', 'kb_draft_from_conversations')->value('action'))->toBe('draft_written');
    $decision = DB::table('ai_decisions')->where('task', 'kb_draft_from_conversations')->first(['response_payload']);
    expect(json_decode((string) $decision->response_payload, true)['message_ids'])->toBe($messageIds);
    expect(DB::table('audit_log')->whereIn('action_key', ['kb.variant.published', 'kb.variant.self_published'])->where('entity_id', $vid)->exists())->toBeFalse('BR-02');
});
