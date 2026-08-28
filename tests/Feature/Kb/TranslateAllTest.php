<?php

declare(strict_types=1);

use App\Domain\Ai\Providers\ProviderResponse;
use App\Jobs\Kb\KbTranslateVariantJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 §14 / FR-06 Translate variant / BR-02 / AC-09 runtime half (S10 step 8): one click creates
 * an AI-sourced DRAFT per missing active language and queues one translate job each (AI from
 * queued jobs only); the job writes the working copy + title through the editor's own doors,
 * logs the decision, audits — and NEVER touches status. Digit drift = refused, copy stays empty.
 * A redelivery calls no model. The click is idempotent (nothing to add → nothing queued).
 */
function runTranslateJob(KbTranslateVariantJob $job): void
{
    $job->handle(
        app(App\Domain\Ai\Orchestrator\AiOrchestrator::class), app(App\Domain\Ai\Prompt\LayeredAssembly::class), app(App\Domain\Ai\Prompt\PiiRedaction::class),
        app(App\Domain\Kb\Editor\WorkingCopy::class), app(App\Domain\Kb\Articles\ArticleWriter::class), app(App\Domain\Ai\DecisionLog\DecisionWriter::class), app(App\Support\Audit\AuditLogger::class),
    );
}

it('§14: one click → an am DRAFT born translation_source=ai + one queued job; the job writes copy + title, logs translate, audits, leaves status alone (BR-02); redelivery calls no model; a second click queues nothing', function (): void {
    Bus::fake([KbTranslateVariantJob::class]);
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Deposits');
    $editor = kbActor(['kb.articles.edit'], 'editor');
    $this->actingAs(kbActor(['kb.articles.create'], 'creator'), 'staff')->postJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/translate-all')->assertStatus(403);
    bindTenant($s['tenant']);

    $click = $this->actingAs($editor, 'staff')->postJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/translate-all');
    $click->assertStatus(202);
    bindTenant($s['tenant']);
    expect($click->json('queued'))->toHaveCount(1)->and($click->json('queued.0.language'))->toBe('am');
    $amId = (string) $click->json('queued.0.variant_id');
    $row = DB::table('kb_article_variants')->where('id', $amId)->first(['status', 'translation_source', 'translation_state', 'working_copy', 'language']);
    expect($row->status)->toBe('draft')->and($row->translation_source)->toBe('ai')->and($row->translation_state)->toBe('missing')->and(KbTranslateVariantJob::textBlocks(json_decode((string) $row->working_copy, true)))->toBe([], 'born empty');
    expect(DB::table('audit_log')->where('action_key', 'kb.article.translate_all_requested')->where('entity_id', $a['article_id'])->exists())->toBeTrue();
    Bus::assertDispatched(KbTranslateVariantJob::class, fn (KbTranslateVariantJob $job) => $job->variantId === $amId && $job->sourceVariantId === $a['variant_id']);

    $driver = copilotDriver(new ProviderResponse(json_encode([['id' => '__title', 'text' => '[FIXTURE] ተቀማጮች'], ['id' => 'b1', 'text' => '[FIXTURE] በቴሌብር የሚደረጉ ተቀማጮች በ30 ደቂቃ ውስጥ ይደርሳሉ።']], JSON_UNESCAPED_UNICODE), 120, 60));
    runTranslateJob(new KbTranslateVariantJob($amId, $a['variant_id'], $editor->identifier()));

    $row = DB::table('kb_article_variants')->where('id', $amId)->first(['status', 'title', 'working_copy']);
    expect($row->status)->toBe('draft', 'BR-02: the AI path never publishes')
        ->and($row->title)->toBe('[FIXTURE] ተቀማጮች')
        ->and(json_decode((string) $row->working_copy, true)['blocks'][0]['text'])->toBe('[FIXTURE] በቴሌብር የሚደረጉ ተቀማጮች በ30 ደቂቃ ውስጥ ይደርሳሉ።')
        ->and(DB::table('ai_decisions')->where('task', 'translate')->where('action', 'variant_translated')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'kb.variant.ai_translated')->where('entity_id', $amId)->exists())->toBeTrue()
        ->and($driver->seen[0]['messages'][0]['content'])->toContain('never convert, round, localize or reorder digits');

    runTranslateJob(new KbTranslateVariantJob($amId, $a['variant_id'], $editor->identifier()));
    expect(count($driver->seen))->toBe(1, 'a redelivery must not call the model again');

    $second = $this->actingAs($editor, 'staff')->postJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/translate-all');
    $second->assertStatus(202);
    bindTenant($s['tenant']);
    expect($second->json('queued'))->toBe([])->and(DB::table('kb_article_variants')->where('article_id', $a['article_id'])->count())->toBe(2);
});

it('§14 guard: altered digits → refused, working copy stays EMPTY (absence), decision guard_refused; an empty source is refused at the door (422)', function (): void {
    Bus::fake([KbTranslateVariantJob::class]);
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Deposits');
    $click = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/translate-all');
    $click->assertStatus(202);
    bindTenant($s['tenant']);
    $amId = (string) $click->json('queued.0.variant_id');

    copilotDriver(new ProviderResponse(json_encode([['id' => '__title', 'text' => '[FIXTURE] ተቀማጮች'], ['id' => 'b1', 'text' => '[FIXTURE] ተቀማጮች በ45 ደቂቃ ውስጥ ይደርሳሉ።']], JSON_UNESCAPED_UNICODE), 120, 60));
    runTranslateJob(new KbTranslateVariantJob($amId, $a['variant_id'], $s['admin']->identifier()));
    expect(KbTranslateVariantJob::textBlocks(json_decode((string) DB::table('kb_article_variants')->where('id', $amId)->value('working_copy'), true)))->toBe([], 'refused — nothing half-written')
        ->and(DB::table('ai_decisions')->where('task', 'translate')->value('error_category'))->toBe('guard_refused')
        ->and(DB::table('audit_log')->where('action_key', 'kb.variant.ai_translated')->exists())->toBeFalse();

    $empty = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/articles', ['category_id' => $s['category_id'], 'audience' => 'public', 'source_language' => 'en', 'title' => '[FIXTURE] Empty']);
    $empty->assertStatus(201);
    bindTenant($s['tenant']);
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/articles/'.$empty->json('article_id').'/translate-all')->assertStatus(422);
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_variants')->where('article_id', $empty->json('article_id'))->count())->toBe(1, 'no variant minted for an empty source');
});
