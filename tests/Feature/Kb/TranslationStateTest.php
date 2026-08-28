<?php

declare(strict_types=1);

use App\Domain\Kb\Translation\VariantForLanguage;
use App\Jobs\Kb\KbTranslationStateConsumer;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-03 / NFR-05 / BR-05 / EC-01 / AC-02 (S10 step 8): a SOURCE-language publish
 * flips every other published sibling to `outdated` — event-driven through the durable
 * consumer, never a scan; the sibling's own publish flips it back to `current`; BR-05:
 * the outdated variant STILL SERVES its language (retrieval untouched, the resolver
 * serves it) and the fallback chain applies ONLY to `missing`; EC-01: the translator's
 * open working copy is untouched by the source publish; per-variant independence (EC-04).
 */
function tsConsume(string $variantId): void
{
    $row = DB::table('outbox')->where('name', 'kb.article.published')->where('payload->variant_id', $variantId)->orderByDesc('id')->first();
    DB::table('events')->insertOrIgnore(['id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'schema_version' => $row->schema_version ?? 1, 'payload' => $row->payload]);
    (new KbTranslationStateConsumer($row->id))->handle();
}

it('AC-02: the matrix — source publish → published siblings outdated (the consumer, ≤5 s trivially); sibling publish → current; a DRAFT sibling stays missing; EC-01 the open working copy survives; redelivery is a no-op', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Deposit guide'); // en = the source language
    $am = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/variants', ['language' => 'am', 'title' => 'የተቀማጭ መመሪያ']);
    $am->assertStatus(201);
    bindTenant($s['tenant']);
    $this->actingAs($s['admin'], 'staff')->putJson('https://localhost/admin/kb/variants/'.$am->json('variant_id').'/working-copy', ['content' => ['blocks' => [['type' => 'paragraph', 'text' => '[FIXTURE] ተቀማጭ በ30 ደቂቃ ውስጥ ይደርሳል።']]]])->assertOk();
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$am->json('variant_id').'/publish')->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_variants')->where('id', $am->json('variant_id'))->value('translation_state'))->toBe('current');

    // EC-01: the translator reopens the am editor (a fresh unsaved working copy)…
    $this->actingAs($s['admin'], 'staff')->putJson('https://localhost/admin/kb/variants/'.$am->json('variant_id').'/working-copy', ['content' => ['blocks' => [['id' => 'wip', 'type' => 'paragraph', 'text' => '[FIXTURE] በአርትዖት ላይ ያለ ጽሑፍ']]]])->assertOk();
    bindTenant($s['tenant']);
    // …then the SOURCE publishes.
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    tsConsume($a['variant_id']);
    expect(DB::table('kb_article_variants')->where('id', $am->json('variant_id'))->value('translation_state'))->toBe('outdated')
        ->and(DB::table('kb_article_variants')->where('id', $a['variant_id'])->value('translation_state'))->toBe('current', 'the source itself is current')
        ->and(json_decode((string) DB::table('kb_article_variants')->where('id', $am->json('variant_id'))->value('working_copy'), true)['blocks'][0]['id'])->toBe('wip', 'EC-01: the open working copy is untouched');
    tsConsume($a['variant_id']); // redelivery
    expect(DB::table('kb_article_variants')->where('id', $am->json('variant_id'))->value('translation_state'))->toBe('outdated');

    // The sibling republishes → current again (its own door writes it).
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$am->json('variant_id').'/publish')->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_article_variants')->where('id', $am->json('variant_id'))->value('translation_state'))->toBe('current');

    // A draft third language is MISSING territory, untouched by the consumer.
    $om = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/variants', ['language' => 'om', 'title' => '[FIXTURE] Qajeelfama']);
    bindTenant($s['tenant']);
    tsConsume($a['variant_id']);
    expect(DB::table('kb_article_variants')->where('id', $om->json('variant_id'))->value('translation_state'))->toBe('missing');
});

it('BR-05: the OUTDATED exact-language variant serves (stale-but-published beats fallback); the chain applies only to MISSING; retrieval is untouched by the badge', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Withdrawals'); // en source
    $am = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/variants', ['language' => 'am', 'title' => 'ወጪዎች']);
    bindTenant($s['tenant']);
    $this->actingAs($s['admin'], 'staff')->putJson('https://localhost/admin/kb/variants/'.$am->json('variant_id').'/working-copy', ['content' => ['blocks' => [['type' => 'paragraph', 'text' => '[FIXTURE] ወጪ በአንድ ቀን ውስጥ ይደርሳል።']]]])->assertOk();
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$am->json('variant_id').'/publish')->assertOk();
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    tsConsume($a['variant_id']);
    $resolver = app(VariantForLanguage::class);
    $served = $resolver->resolve($a['article_id'], ['am', 'en']);
    expect($served['variant_id'])->toBe($am->json('variant_id'), 'BR-05: outdated am serves the am reader')
        ->and($served['translation_state'])->toBe('outdated')->and($served['served_language_matches'])->toBeTrue();
    $missing = $resolver->resolve($a['article_id'], ['om', 'en']);
    expect($missing['variant_id'])->toBe($a['variant_id'], 'missing om falls back down the chain')->and($missing['served_language_matches'])->toBeFalse();
});
