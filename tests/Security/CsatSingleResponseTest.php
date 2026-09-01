<?php

declare(strict_types=1);

use App\Domain\Csat\ResponseIngest;
use App\Domain\Csat\SurveyArmer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 12 SEC-05 / AC-08 — token binding, rate limit, single response, and the
| edit window's hard close (the row-unique physics half lives in
| SlaGrantPhysicsTest — countersign obligation 1)
|--------------------------------------------------------------------------
*/

it('SEC-05: the RAW token exists only in the delivery — the row holds its hash; a wrong token answers unknown and records nothing', function (): void {
    [$tenant, $ticket, $conversationId] = csatScene();
    app(SurveyArmer::class)->armFromEvent(publishTicketResolved($tenant, $ticket));
    $token = csatTokenFromDelivery($conversationId);

    /** @var object{token_hash: string} $survey */
    $survey = DB::selectOne('SELECT token_hash FROM surveys');
    expect($survey->token_hash)->toBe(hash('sha256', $token))
        ->and($survey->token_hash)->not->toBe($token);

    expect(app(ResponseIngest::class)->capture(Str::random(48), 5))->toBe(['state' => 'unknown'])
        ->and((int) DB::table('survey_responses')->count())->toBe(0);
});

it('SEC-05: the door rate-limits per token before touching any row', function (): void {
    [$tenant, $ticket, $conversationId] = csatScene();
    app(SurveyArmer::class)->armFromEvent(publishTicketResolved($tenant, $ticket));
    $token = csatTokenFromDelivery($conversationId);
    $ingest = app(ResponseIngest::class);

    $states = [];
    for ($i = 0; $i < 12; $i++) {
        $states[] = $ingest->capture($token, 3)['state'];
    }
    expect($states)->toContain('rate_limited')
        ->and($states[11])->toBe('rate_limited');
});

it('FR-25: beyond the 1 h edit window the response is LOCKED — the standing score never moves', function (): void {
    [$tenant, $ticket, $conversationId] = csatScene();
    app(SurveyArmer::class)->armFromEvent(publishTicketResolved($tenant, $ticket));
    $token = csatTokenFromDelivery($conversationId);
    $ingest = app(ResponseIngest::class);
    expect($ingest->capture($token, 5)['state'])->toBe('scored');

    // responded_at is app-immutable (grant physics) — the drill backdates as owner
    // (commit first: the owner's session cannot see the test transaction).
    commitFixtures();
    DB::connection('pgsql_migrator')->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenant->identifier()]);
    DB::connection('pgsql_migrator')->statement("UPDATE survey_responses SET responded_at = clock_timestamp() - interval '2 hours'");

    expect($ingest->capture($token, 1, 'changed my mind')['state'])->toBe('locked');
    /** @var object{score: int, comment: ?string} $row */
    $row = DB::selectOne('SELECT score, comment FROM survey_responses');
    expect($row->score)->toBe(5)->and($row->comment)->toBeNull();
});
