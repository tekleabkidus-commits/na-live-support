<?php

declare(strict_types=1);

use App\Domain\Kb\Analytics\ArticleStats;
use App\Domain\Kb\Analytics\UsageEvents;
use App\Domain\Kb\Feedback\FeedbackWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-11 / FR-12 / EC-10 / AC-08 / §7 (S10 step 10): customer scores dedupe by the DB arbiter
 * (one score per visitor per article, latest wins — an UPDATE, never a second row), every landed score
 * emits kb.feedback.received (identifiers + categories, no comment on the bus), staff notes are the
 * same table without a score/event, actioned is audited, and the stats header RECONCILES against the
 * Doc 15 input fixture (fixtures/kb/analytics/doc15-stats.json) — the deflection formula stays Doc 15's.
 */
function publishedKb(array $s, string $title = '[FIXTURE] Withdrawals'): array
{
    $a = kbArticle($s, $title);
    $p = test()->actingAs($s['admin'], 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/publish');
    $p->assertOk();
    bindTenant($s['tenant']);

    return $a + ['version_id' => $p->json('version_id')];
}

it('EC-10 dedupe: one score per visitor per article, latest wins (row count 1, UPDATE path), each landing emits kb.feedback.received without the comment; unpublished variants refuse; staff notes + actioned are audited', function (): void {
    $s = kbScene();
    $a = publishedKb($s);
    $w = app(FeedbackWriter::class);
    $first = $w->customer($a['variant_id'], 'visitor-hash-1', -1, '[FIXTURE] not clear <script>alert(1)</script>', 'widget');
    $second = $w->customer($a['variant_id'], 'visitor-hash-1', 1, null, 'widget');
    expect($first['replaced'])->toBeFalse()->and($second['replaced'])->toBeTrue()->and($second['feedback_id'])->toBe($first['feedback_id']);
    $rows = DB::table('kb_feedback')->where('variant_id', $a['variant_id'])->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->score)->toBe(1)->and($rows[0]->comment)->toBeNull()
        ->and(DB::table('outbox')->where('name', 'kb.feedback.received')->where('payload->variant_id', $a['variant_id'])->count())->toBe(2);
    $payload = json_decode((string) DB::table('outbox')->where('name', 'kb.feedback.received')->orderBy('id')->value('payload'), true);
    expect(array_keys($payload))->not->toContain('comment')->and($payload['score'])->toBe(-1)->and($payload['has_comment'])->toBeTrue();

    // A different visitor is a second row (the arbiter is per visitor).
    $w->customer($a['variant_id'], 'visitor-hash-2', 1, null, 'telegram');
    expect(DB::table('kb_feedback')->where('variant_id', $a['variant_id'])->count())->toBe(2);

    $draft = kbArticle($s, '[FIXTURE] Draft only');
    expect(fn () => $w->customer($draft['variant_id'], 'visitor-hash-1', 1, null, 'widget'))->toThrow(InvalidArgumentException::class);

    // N-20 (batch-C gate fix): the writer holds NO source enumeration — an unregistered surface is
    // refused by PHYSICS (kb_feedback_source_check on the insert; the update grant omits source), never
    // by a channel conditional in domain code. The savepoint-wrapped refusal writes nothing.
    expect(fn () => $w->customer($a['variant_id'], 'visitor-hash-3', 1, null, 'sms'))->toThrow(Illuminate\Database\QueryException::class);
    expect(DB::table('kb_feedback')->where('visitor_hash', 'visitor-hash-3')->exists())->toBeFalse();

    $note = $this->actingAs(kbActor([], 'agent'), 'staff')->postJson('https://localhost/admin/kb/variants/'.$a['variant_id'].'/feedback/note', ['comment' => '[FIXTURE] customers keep asking about fees']);
    $note->assertStatus(201);
    bindTenant($s['tenant']);
    expect(DB::table('kb_feedback')->where('id', $note->json('feedback_id'))->value('staff_id'))->not->toBeNull()
        ->and(DB::table('audit_log')->where('action_key', 'kb.feedback.noted')->where('entity_id', $note->json('feedback_id'))->exists())->toBeTrue()
        ->and(DB::table('outbox')->where('name', 'kb.feedback.received')->count())->toBe(3, 'a staff note is not a customer signal');

    $viewer = kbActor(['kb.feedback.view'], 'viewer');
    $this->actingAs(kbActor([], 'agent2'), 'staff')->postJson('https://localhost/admin/kb/feedback/'.$first['feedback_id'].'/action')->assertStatus(403);
    bindTenant($s['tenant']);
    $this->actingAs($viewer, 'staff')->postJson('https://localhost/admin/kb/feedback/'.$first['feedback_id'].'/action')->assertOk();
    bindTenant($s['tenant']);
    expect(DB::table('kb_feedback')->where('id', $first['feedback_id'])->value('actioned_at'))->not->toBeNull()
        ->and(DB::table('audit_log')->where('action_key', 'kb.feedback.actioned')->where('entity_id', $first['feedback_id'])->exists())->toBeTrue();
    $list = $this->actingAs($viewer, 'staff')->getJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/feedback?limit=2');
    $list->assertOk();
    expect($list->json('data'))->toHaveCount(2)->and($list->json('next_cursor'))->not->toBeNull();
});

it('AC-08: the stats header reconciles against the Doc 15 input fixture — views by surface, citations, inserts, helpful % with dedupe, deflection CANDIDATES (formula [D15]); a draft render is not a view', function (): void {
    $s = kbScene();
    $a = publishedKb($s);
    $fixture = json_decode((string) file_get_contents(base_path('fixtures/kb/analytics/doc15-stats.json')), true);
    $usage = app(UsageEvents::class);
    $events = app(App\Support\Events\EventPublisher::class);
    $conversations = [];
    foreach ($fixture['events'] as $e) {
        $conv = $e['conversation'] ?? null;
        $convId = null;
        if ($conv !== null) {
            $conversations[$conv] ??= anotherConversation($s['tenant_id'], $conv);
            $convId = $conversations[$conv]->identifier();
        }
        if ($e['name'] === 'kb.article.viewed') {
            $usage->viewed($a['variant_id'], $e['surface'], $convId);
        } else {
            $events->publish($e['name'], ['tenant_id' => $s['tenant_id'], 'article_id' => $a['article_id'], 'version_id' => $a['version_id'], 'conversation_id' => $convId] + ($e['name'] === 'kb.article.cited_by_ai' ? ['decision_id' => (string) Str::uuid7()] : ['agent_id' => $s['admin']->identifier()]));
        }
    }
    // The header reads the EVENTS table (the relay's durable record): replay the outbox rows as the relay would.
    foreach (DB::table('outbox')->whereIn('name', ['kb.article.viewed', 'kb.article.cited_by_ai', 'kb.article.inserted_by_agent'])->get() as $row) {
        DB::table('events')->insertOrIgnore(['id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'schema_version' => 1, 'payload' => $row->payload]);
    }
    foreach ($fixture['events'] as $e) {
        if (($e['ticket_within_24h'] ?? false) === true) {
            inboxTicketRow($s['tenant_id'], $conversations[$e['conversation']], 'open');
        }
    }
    $w = app(FeedbackWriter::class);
    foreach ($fixture['feedback'] as $f) {
        $w->customer($a['variant_id'], 'visitor-'.$f['visitor'], $f['score'], $f['comment'], 'widget');
    }
    $draft = kbArticle($s, '[FIXTURE] Draft render');
    $usage->viewed($draft['variant_id'], 'staff');

    $direct = app(ArticleStats::class)->forArticle($a['article_id']); // the service first: a failure surfaces here, not as a masked 500
    $header = $this->actingAs(kbActor(['kb.feedback.view'], 'viewer'), 'staff')->getJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/stats');
    $header->assertOk();
    bindTenant($s['tenant']);
    $expected = $fixture['expected'];
    expect($header->json('views'))->toBe($expected['views'])->and($header->json('citations'))->toBe($expected['citations'])->and($header->json('inserts'))->toBe($expected['inserts'])
        ->and($header->json('feedback'))->toBe($expected['feedback'])->and($header->json('deflection_candidates'))->toBe($expected['deflection_candidates'])
        ->and($header->json('deflection_formula'))->toBe('doc15')
        ->and(DB::table('outbox')->where('name', 'kb.article.viewed')->where('payload->variant_id', $draft['variant_id'])->exists())->toBeFalse('a draft render is not a view');
    $this->actingAs(kbActor([], 'agent'), 'staff')->getJson('https://localhost/admin/kb/articles/'.$a['article_id'].'/stats')->assertStatus(403);
});
