<?php

declare(strict_types=1);

use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Csat\CsatSweep;
use App\Domain\Csat\ResponseIngest;
use App\Domain\Csat\SurveyArmer;
use App\Support\Events\EventPublisher;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-25 / EC-11 / T-08 / AC-08 — capture: 1-tap score, comment prompt,
| the 1 h edit REPLACES, hour-73 politely refuses, low scores raise review,
| AI-resolver scores land csat_scored on the decision row (closing rows 2/4)
|--------------------------------------------------------------------------
*/

it('FR-25: the 1-tap score lands, prompts for a comment, and an in-window edit REPLACES (one row, csat.received re-emitted)', function (): void {
    [$tenant, $ticket, $conversationId] = csatScene();
    app(SurveyArmer::class)->armFromEvent(publishTicketResolved($tenant, $ticket));
    $token = csatTokenFromDelivery($conversationId);
    $ingest = app(ResponseIngest::class);

    $result = $ingest->capture($token, 4);
    expect($result)->toBe(['state' => 'scored', 'comment_prompt' => true]);
    $edit = $ingest->capture($token, 2, 'still waiting on my 2,500 birr');
    expect($edit['state'])->toBe('scored');

    /** @var object{score: int, comment: string, edited: bool} $row */
    $row = DB::selectOne('SELECT score, comment, (edited_at IS NOT NULL) AS edited FROM survey_responses');
    expect((int) DB::table('survey_responses')->count())->toBe(1, 'the edit must REPLACE — never a second row')
        ->and($row->score)->toBe(2)->and($row->comment)->toContain('2,500')->and($row->edited)->toBeTrue();
    expect((int) DB::table('outbox')->where('name', 'csat.received')->count())->toBe(2);

    // Score ≤ 2 → the Doc 09 FR-20 review item on the supervisor feed.
    /** @var object{action: string, response_payload: string}|null $review */
    $review = DB::selectOne("SELECT action, response_payload::text AS response_payload FROM ai_decisions WHERE surface = 'supervisor' AND task = 'qa_prescore' AND response_payload->>'kind' = 'low_csat_review'");
    expect($review)->not->toBeNull('no review item raised for a low score')
        ->and($review->action)->toBe('review_item')
        ->and(json_decode($review->response_payload, true)['score'])->toBe(2);
});

it('EC-11: an hour-73 tap records NOTHING, answers the polite window-closed copy (en+am), and csat.expired stands exactly once', function (): void {
    [$tenant, $ticket, $conversationId] = csatScene();
    app(SurveyArmer::class)->armFromEvent(publishTicketResolved($tenant, $ticket));
    $token = csatTokenFromDelivery($conversationId);
    // expires_at is app-immutable (grant physics) — the drill backdates as owner
    // (commit first: the owner's session cannot see the test transaction).
    commitFixtures();
    DB::connection('pgsql_migrator')->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenant->identifier()]);
    DB::connection('pgsql_migrator')->statement("UPDATE surveys SET expires_at = clock_timestamp() - interval '1 hour'");

    $result = app(ResponseIngest::class)->capture($token, 5);
    expect($result['state'])->toBe('window_closed')
        ->and($result['text_key'])->toBe('workflow.csat.window_closed')
        ->and(__('workflow.csat.window_closed', [], 'en'))->not->toBe('workflow.csat.window_closed')
        ->and(__('workflow.csat.window_closed', [], 'am'))->not->toBe('workflow.csat.window_closed');
    expect((int) DB::table('survey_responses')->count())->toBe(0)
        ->and((int) DB::table('outbox')->where('name', 'csat.expired')->count())->toBe(1);
    // The sweep passing later finds nothing left to flip (idempotent, both doors one flip).
    app(CsatSweep::class)->expire((string) DB::table('surveys')->value('id'), $ticket->identifier());
    expect((int) DB::table('outbox')->where('name', 'csat.expired')->count())->toBe(1);
});

it('closing rows 2/4: an AI-resolver score lands csat_scored on the RESOLVING DECISION through Doc 09\'s own enrichment door', function (): void {
    $tenant = createTenant();
    registerFakeChannel();
    [, $conversation] = conversationFixture();
    $writer = app(DecisionWriter::class);
    $record = decisionFixture(['conversationId' => $conversation->identifier(), 'action' => 'resolve']);
    $writer->record($record);
    $writer->flush();

    $aiEventId = DB::transaction(fn (): string => app(EventPublisher::class)->publish('ai.resolved', [
        'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversation->identifier(),
        'decision_id' => $record->id,
    ]));
    app(SurveyArmer::class)->armFromEvent($aiEventId);
    app(ResponseIngest::class)->capture(csatTokenFromDelivery($conversation->identifier()), 5);

    /** @var object{outcome: string, outcome_meta: string} $decision */
    $decision = DB::selectOne('SELECT outcome, outcome_meta::text AS outcome_meta FROM ai_decisions WHERE id = ?', [$record->id]);
    expect($decision->outcome)->toBe('csat_scored')
        ->and(json_decode($decision->outcome_meta, true))->toBe(['score' => 5]);
    /** @var object{payload: string} $received */
    $received = DB::table('outbox')->where('name', 'csat.received')->first(['payload']);
    expect(json_decode($received->payload, true)['resolver_type'])->toBe('ai');
});
