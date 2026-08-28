<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * S10 step 7b packet P3 (Doc 10 FR-12 / Doc 09 FR-14): insert-as-link is the AGENT'S ACT —
 * the door (gated ai.copilot.insert_draft, FR-14's own key) emits kb.article.inserted_by_agent
 * at the click, never on render; a legacy ref inserts bare with no event; a reader without
 * the insert key is refused (the Read Only no-affordance rule server-side); BR-01 untouched
 * (the door returns TEXT for the composer draft — nothing sends).
 */
it('P3: the door emits the event with the version pin and returns the draft line; renders mint nothing; no insert key → 403; legacy ref → bare text, no event', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Verification guide');
    $published = $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish');
    $published->assertOk();
    bindTenant($s['tenant']);
    $agent = kbActor(['ai.copilot.insert_draft'], 'agent');
    $readOnly = kbActor([], 'reader');

    expect(DB::table('outbox')->where('name', 'kb.article.inserted_by_agent')->count())->toBe(0, 'rendering the card minted nothing');

    $inserted = $this->actingAs($agent, 'staff')->postJson('https://localhost/admin/kb/insert-link', ['article_id' => $a['article_id'], 'conversation_id' => '018f6b2e-0000-7000-8000-00000000c0f1']);
    $inserted->assertOk();
    expect($inserted->json('text'))->toBe('📄 [FIXTURE] Verification guide /kb/cite/'.$a['article_id'].'/'.$published->json('version_id'))
        ->and($inserted->json('recorded'))->toBeTrue();
    bindTenant($s['tenant']);
    $payload = json_decode((string) DB::table('outbox')->where('name', 'kb.article.inserted_by_agent')->value('payload'), true);
    expect($payload['article_id'])->toBe($a['article_id'])->and($payload['version_id'])->toBe($published->json('version_id'))->and($payload['agent_id'])->toBe($agent->identifier());

    $this->actingAs($readOnly, 'staff')->postJson('https://localhost/admin/kb/insert-link', ['article_id' => $a['article_id'], 'conversation_id' => '018f6b2e-0000-7000-8000-00000000c0f1'])->assertStatus(403);

    $legacy = $this->actingAs($agent, 'staff')->postJson('https://localhost/admin/kb/insert-link', ['article_id' => '018f6b2e-0000-7000-8000-00000000babe', 'conversation_id' => '018f6b2e-0000-7000-8000-00000000c0f1']);
    $legacy->assertOk();
    expect($legacy->json('recorded'))->toBeFalse();
    bindTenant($s['tenant']);
    expect(DB::table('outbox')->where('name', 'kb.article.inserted_by_agent')->count())->toBe(1);
});
