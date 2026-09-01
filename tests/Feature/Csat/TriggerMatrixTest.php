<?php

declare(strict_types=1);

use App\Domain\Csat\SurveyArmer;
use App\Domain\Customers\Rg\RgFlagService;
use App\Support\Events\EventPublisher;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-24 / BR-07 / AC-08 — the trigger matrix: both resolution paths,
| one survey per resolution event, cooldown- and RG-suppressed
|--------------------------------------------------------------------------
*/

it('FR-24: a human resolution arms ONE survey (idempotent on the resolution event), materializes the window, and delivers the 1-tap prompt', function (): void {
    [$tenant, $ticket, $conversationId, $customerId] = csatScene();
    $eventId = publishTicketResolved($tenant, $ticket);
    $armer = app(SurveyArmer::class);

    $first = $armer->armFromEvent($eventId);
    expect($first['armed'])->toBeTrue();
    // C-09: a re-driven consumer changes nothing.
    expect($armer->armFromEvent($eventId))->toBe(['armed' => false, 'reason' => 'already_armed']);
    expect((int) DB::table('surveys')->count())->toBe(1)
        ->and((int) DB::table('outbox')->where('name', 'csat.sent')->count())->toBe(1);

    /** @var object{resolver_type: string, token_hash: string, ticket_id: string, in_window: bool} $survey */
    $survey = DB::selectOne(
        "SELECT resolver_type, token_hash, ticket_id, (expires_at BETWEEN clock_timestamp() + interval '71 hours' AND clock_timestamp() + interval '73 hours') AS in_window FROM surveys LIMIT 1");
    expect($survey->resolver_type)->toBe('human')
        ->and($survey->ticket_id)->toBe($ticket->identifier())
        ->and($survey->in_window)->toBeTrue()
        ->and(strlen($survey->token_hash))->toBe(64);
    // The prompt went out with the token as channel meta; both launch languages exist.
    expect(csatTokenFromDelivery($conversationId))->not->toBe('')
        ->and(__('workflow.csat.prompt', [], 'en'))->not->toBe('workflow.csat.prompt')
        ->and(__('workflow.csat.prompt', [], 'am'))->not->toBe('workflow.csat.prompt');
});

it('FR-24 cooldown: the SAME customer resolved again within csat.cooldown.hours is not re-surveyed (an ai.resolved on their conversation suppresses too)', function (): void {
    [$tenant, $ticket, $conversationId] = csatScene();
    $armer = app(SurveyArmer::class);
    expect($armer->armFromEvent(publishTicketResolved($tenant, $ticket))['armed'])->toBeTrue();

    $aiEventId = DB::transaction(fn (): string => app(EventPublisher::class)->publish('ai.resolved', [
        'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversationId,
        'decision_id' => (string) Illuminate\Support\Str::uuid7(),
    ]));
    expect($armer->armFromEvent($aiEventId))->toBe(['armed' => false, 'reason' => 'cooldown_suppressed']);
    expect((int) DB::table('surveys')->count())->toBe(1);
});

it('FR-24 RG suppression (Doc 08 FR-15\'s contract): an RG-flagged player is NEVER surveyed', function (): void {
    [$tenant, $ticket, , $customerId] = csatScene();
    app(RgFlagService::class)->set($customerId, 'fixture: self-exclusion mention', 'staff', grantAllPermissions(ticketActor([]))->identifier());

    $verdict = app(SurveyArmer::class)->armFromEvent(publishTicketResolved($tenant, $ticket));
    expect($verdict)->toBe(['armed' => false, 'reason' => 'rg_suppressed'])
        ->and((int) DB::table('surveys')->count())->toBe(0)
        ->and((int) DB::table('outbox')->where('name', 'csat.sent')->count())->toBe(0);
});

it('RIDER 4: an ai.resolved conversation with NO ticket arms a CONVERSATION-anchored survey, resolver ai (closing row 2\'s seam, armed for real)', function (): void {
    $tenant = createTenant();
    registerFakeChannel();
    [, $conversation] = conversationFixture();
    $aiEventId = DB::transaction(fn (): string => app(EventPublisher::class)->publish('ai.resolved', [
        'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversation->identifier(),
        'decision_id' => (string) Illuminate\Support\Str::uuid7(),
    ]));

    $verdict = app(SurveyArmer::class)->armFromEvent($aiEventId);
    expect($verdict['armed'])->toBeTrue();
    /** @var object{ticket_id: ?string, conversation_id: ?string, resolver_type: string} $survey */
    $survey = DB::selectOne('SELECT ticket_id, conversation_id, resolver_type FROM surveys LIMIT 1');
    expect($survey->ticket_id)->toBeNull()
        ->and($survey->conversation_id)->toBe($conversation->identifier())
        ->and($survey->resolver_type)->toBe('ai');
    /** @var object{payload: string} $sent */
    $sent = DB::table('outbox')->where('name', 'csat.sent')->first(['payload']);
    expect(json_decode($sent->payload, true)['ticket_id'])->toBeNull();
});
