<?php

declare(strict_types=1);

use App\Domain\Kb\Feedback\FeedbackRefused;
use App\Domain\Kb\Feedback\FeedbackWriter;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Kb/KbSceneHelpers.php';

/**
 * Doc 10 SEC-05 / EC-10 (S10 step 10): feedback is a hostile-input surface — per-visitor rate limit
 * (kb.feedback.rate_per_hour, redis-volatile, TTL-bound) with the refused attempts counted as
 * anomalies for the header (the bus-level anomaly event = amendment A-48), a comment length cap
 * (kb.feedback.comment_max_chars), INERT storage (scripts/handlers/javascript: stripped at write),
 * and the visitor as a HASH — no customer identity column exists on the table (N-06 posture).
 */
it('rate limit per visitor per hour: the (N+1)th write is refused as rate_limited, counted as an anomaly, and consumes nothing; another visitor is unaffected', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Limits');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    app(SettingsRepository::class)->set('kb.feedback.rate_per_hour', 3, tenantSurface: false);
    $w = app(FeedbackWriter::class);
    for ($i = 0; $i < 3; $i++) {
        $w->customer($a['variant_id'], 'bomber', $i % 2 === 0 ? -1 : 1, null, 'widget');
    }
    try {
        $w->customer($a['variant_id'], 'bomber', -1, null, 'widget');
        $this->fail('the 4th write must be refused');
    } catch (FeedbackRefused $e) {
        expect($e->reason)->toBe('rate_limited');
    }
    expect(DB::table('kb_feedback')->where('variant_id', $a['variant_id'])->count())->toBe(1, 'dedupe held throughout: one row for the bomber')
        ->and(DB::table('kb_feedback')->where('variant_id', $a['variant_id'])->value('score'))->toBe(-1) /* the refused attempt changed nothing — the latest ACCEPTED (-1, i=2) wins */
        ->and(app(App\Domain\Kb\Feedback\VisitorLimiter::class)->anomaliesToday())->toBe(1);
    $w->customer($a['variant_id'], 'someone-else', 1, null, 'telegram');
    expect(DB::table('kb_feedback')->where('variant_id', $a['variant_id'])->count())->toBe(2);
});

it('length cap + inert storage: an over-cap comment is refused (too_long, nothing written); scripts, handlers and javascript: URLs are stripped at write; the table carries a visitor HASH and no customer identity', function (): void {
    $s = kbScene();
    $a = kbArticle($s, '[FIXTURE] Inert');
    $this->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish')->assertOk();
    bindTenant($s['tenant']);
    $w = app(FeedbackWriter::class);
    $cap = app(App\Domain\Kb\Feedback\VisitorLimiter::class)->commentCap();
    try {
        $w->customer($a['variant_id'], 'v-long', 1, '[FIXTURE] '.str_repeat('a', $cap + 1), 'widget');
        $this->fail('over-cap must refuse');
    } catch (FeedbackRefused $e) {
        expect($e->reason)->toBe('too_long');
    }
    expect(DB::table('kb_feedback')->where('variant_id', $a['variant_id'])->count())->toBe(0);

    $w->customer($a['variant_id'], 'v-hostile', -1, '[FIXTURE] bad <script>alert(1)</script><a href="javascript:x()" onclick="y()">link</a>', 'widget');
    $stored = (string) DB::table('kb_feedback')->where('variant_id', $a['variant_id'])->value('comment');
    expect($stored)->not->toContain('<script')->not->toContain('onclick')->not->toContain('javascript:')->and($stored)->toContain('[FIXTURE] bad');
    // The staff list renders what is stored — inert already; no unwrap happens on read.
    $list = $this->actingAs(kbActor(['kb.feedback.view'], 'viewer'), 'staff')->getJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/feedback');
    $list->assertOk();
    expect(json_encode($list->json()))->not->toContain('<script')->not->toContain('javascript:');

    $columns = array_map(fn ($r) => $r->column_name, DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'kb_feedback'"));
    expect($columns)->toContain('visitor_hash')->not->toContain('customer_id')->not->toContain('verified');
});
