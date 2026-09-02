<?php

declare(strict_types=1);

use App\Domain\Ai\Pipeline\RouteExecutor;
use App\Domain\Automation\Ai\AutomationAiTasks;
use App\Domain\Automation\Engine\EventContext;
use App\Domain\Routing\Hours\HoursContract;
use App\Jobs\Automation\RunAiTaskJob;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| PACKET P-CLS LANDED (S12 close; countersigned at the recount) — the
| classify-ONLY door and the run-AI-task classify_refresh sub-arm
|--------------------------------------------------------------------------
|
| Controls as filed: a stale intent on the message's decision row → the
| refresh writes a NEW classify_message decision row (surface chatbot, action
| copilot_context, the refresh marker), EventContext's ai.intent fact reads the
| NEW value, and NO route executes — the RouteExecutor SPY counts zero calls and
| the outbound message count is unchanged. classify() stays byte-identical in
| behavior (the same run(); Doc 09's own suites are the control).
*/

/** The scene, local to this file (Pest test-file functions are global — the per-file helper law). */
function classifyRefreshScene(): array
{
    [$tenant, , $ticket] = ticketFixture('open');

    return [$tenant, $ticket];
}

final class RouteExecutorSpy implements RouteExecutor
{
    public int $calls = 0;

    public function execute(string $route, string $decisionId, string $conversationId, string $messageId, string $language, array $classification): void
    {
        $this->calls++;
    }
}

it('P-CLS: classify_refresh writes a NEW decision row the ai.intent fact reads, executes NO route (spy: zero calls; outbound unchanged), and marks the row as an automation refresh', function (): void {
    [$tenant, $ticket] = classifyRefreshScene();
    $conversation = App\Models\Conversation::query()->findOrFail($ticket->getAttribute('conversation_id'));
    // The stale row: intent 'deposits' recorded by the pipeline earlier.
    $messageId = copilotCustomerMessage($tenant->identifier(), $conversation, '[FIXTURE] ገንዘቤ አልደረሰም — my withdrawal has not arrived', 'am', ['intent' => 'deposits']);
    $before = (int) DB::table('ai_decisions')->where('message_id', $messageId)->where('task', 'classify_message')->count();
    $outboundBefore = (int) DB::table('messages')->where('conversation_id', $conversation->identifier())->where('direction', 'out')->count();

    $spy = new RouteExecutorSpy;
    app()->instance(RouteExecutor::class, $spy);
    copilotDriver(new App\Domain\Ai\Providers\ProviderResponse('{"intent":"withdrawals","sentiment":-1,"urgency":2,"spam":0.0,"rg_risk":0.0}', 20, 10));

    $executionId = automationActionScene($tenant, $ticket, [['type' => 'run_ai_task', 'task' => 'classify_refresh']]);
    (new RunAiTaskJob($executionId, 'classify_refresh', $conversation->identifier()))->handle(app(AutomationAiTasks::class));

    $rows = DB::table('ai_decisions')->where('message_id', $messageId)->where('task', 'classify_message')->orderByDesc('created_at')->get();
    expect($rows->count())->toBe($before + 1, 'the refresh must write a NEW row, never rewrite the old one');
    $fresh = $rows->first();
    expect($fresh->surface)->toBe('chatbot')
        ->and($fresh->action)->toBe('copilot_context')
        ->and($fresh->intent)->toBe('withdrawals')
        ->and(json_decode((string) $fresh->request_payload, true, 8)['refresh'] ?? null)->toBe('automation');
    expect($spy->calls)->toBe(0, 'a refresh executed a ROUTE — a chatbot turn from a rule');
    expect((int) DB::table('messages')->where('conversation_id', $conversation->identifier())->where('direction', 'out')->count())->toBe($outboundBefore);

    // The FR-02 fact reads the NEW row through EventContext's own assembly (a message.received event).
    // EventContext reads the OUTBOX row by event_id (T-03's envelope); seed one for the message.
    $eventId = (string) Illuminate\Support\Str::uuid7();
    DB::table('outbox')->insert(['id' => $eventId, 'tenant_id' => $tenant->identifier(), 'event_id' => $eventId, 'name' => 'message.received', 'schema_version' => 1, 'payload' => json_encode(['tenant_id' => $tenant->identifier(), 'message_id' => $messageId, 'conversation_id' => $conversation->identifier()])]);
    $context = EventContext::assemble($eventId, app(HoursContract::class));
    expect($context->facts['ai.intent'] ?? null)->toBe('withdrawals', 'the FR-02 ai.intent fact did not read the refreshed row')
        ->and($context->facts['ai.sentiment'] ?? null)->toBe(-1);
});

it('P-CLS: a provider/quota refusal on a refresh answers null — no fallback row, no bypass handoff, nothing executed', function (): void {
    [$tenant, $ticket] = classifyRefreshScene();
    $conversation = App\Models\Conversation::query()->findOrFail($ticket->getAttribute('conversation_id'));
    $messageId = copilotCustomerMessage($tenant->identifier(), $conversation, '[FIXTURE] my withdrawal has not arrived', 'en');
    $before = (int) DB::table('ai_decisions')->where('message_id', $messageId)->count();
    $spy = new RouteExecutorSpy;
    app()->instance(RouteExecutor::class, $spy);
    copilotDriver(new App\Domain\Ai\Providers\AllProvidersExhausted('fixture: every provider down'));

    expect(app(App\Domain\Ai\Pipeline\InboundClassification::class)->classifyOnly($messageId))->toBeNull();
    expect((int) DB::table('ai_decisions')->where('message_id', $messageId)->count())->toBe($before)
        ->and($spy->calls)->toBe(0);
});
