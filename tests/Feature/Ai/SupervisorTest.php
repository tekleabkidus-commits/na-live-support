<?php

declare(strict_types=1);

use App\Domain\Ai\KillSwitch\KillSwitches;
use App\Domain\Ai\Providers\ProviderHealth;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Supervisor\AnomalyScan;
use App\Domain\Ai\Supervisor\DailyDigest;
use App\Domain\Ai\Supervisor\QaPrescore;
use App\Domain\Ai\Supervisor\SentimentWatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 09 FR-17 — the Supervisor surface: anomaly_scan (US-05's +240%
 * scenario), live sentiment watch, qa_prescore (advisory, fixture rubric),
 * daily_digest (6 bullets, per manager scope, localized); FS-01's pause
 * (AC-09's last rung) + the supervisor kill switch; outputs are decision
 * rows (surface supervisor) read through the decision-log door's surface
 * filter.
 */
/**
 * Backdated decision rows need created_at (R-19a: platform-only) → the
 * platform connection over COMMITTED fixtures (the PayloadReductionJobTest
 * pattern); every test here cleans its tenant up in a finally.
 */
function supervisorScene(): array
{
    $scene = copilotScene();
    commitFixtures();
    bindTenant($scene[0]);

    return $scene;
}

function platformDecisionRow(string $tenantId, array $columns, string $createdAtSql): void
{
    $cols = array_keys($columns);
    $placeholders = array_map(static fn (string $c): string => in_array($c, ['layer_hashes', 'response_payload'], true) ? '?::jsonb' : '?', $cols);
    asPlatformWorker(fn () => DB::connection('pgsql_platform')->statement(
        sprintf('INSERT INTO ai_decisions (%s, created_at) VALUES (%s, %s)', implode(', ', $cols), implode(', ', $placeholders), $createdAtSql),
        array_values($columns),
    ));
    bindTenant($tenantId);
}

function classifyRow(string $tenantId, string $conversationId, string $intent, int $sentiment, string $createdAtSql): void
{
    platformDecisionRow($tenantId, [
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'surface' => 'chatbot', 'task' => 'classify_message', 'provider' => 'fake', 'model' => 'm',
        'layer_hashes' => '{}', 'conversation_id' => $conversationId, 'intent' => $intent, 'confidence' => 0.9, 'action' => 'copilot_context',
        'response_payload' => json_encode(['classification' => ['intent' => $intent, 'sentiment' => $sentiment, 'urgency' => 1, 'spam' => 0.0, 'rg_risk' => 0.0, 'confidence' => 0.9]]),
    ], $createdAtSql);
}

it('FR-17/US-05: withdrawal complaints tripling in the last hour vs the same hour\'s 7-day baseline raises an intent alert (+240%) with a template key + params; below the noise floor nothing fires; spam never counts (N-14)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = supervisorScene();
    try {
        // Baseline: the same clock hour on each of the prior 7 days, 5 withdrawals/day → 5.0/h baseline.
        for ($d = 1; $d <= 7; $d++) {
            for ($i = 0; $i < 5; $i++) {
                classifyRow($tenantId, $conversation->identifier(), 'withdrawals', 0, "date_trunc('hour', clock_timestamp()) - interval '1 hour' - interval '{$d} days' + interval '10 minutes'");
            }
        }
        // Current hour: 17 withdrawals (+240%), 2 deposits (below the floor), 30 spam (excluded).
        for ($i = 0; $i < 17; $i++) {
            classifyRow($tenantId, $conversation->identifier(), 'withdrawals', -1, "date_trunc('hour', clock_timestamp()) - interval '30 minutes'");
        }
        for ($i = 0; $i < 2; $i++) {
            classifyRow($tenantId, $conversation->identifier(), 'deposits', 0, "date_trunc('hour', clock_timestamp()) - interval '30 minutes'");
        }
        for ($i = 0; $i < 30; $i++) {
            classifyRow($tenantId, $conversation->identifier(), 'spam', 0, "date_trunc('hour', clock_timestamp()) - interval '30 minutes'");
        }

        $result = app(AnomalyScan::class)->run();

        expect($result['paused'])->toBeNull();
        $intentAlerts = array_values(array_filter($result['alerts'], fn (array $a): bool => $a['kind'] === 'intent'));
        expect($intentAlerts)->toHaveCount(1)
            ->and($intentAlerts[0]['intent'])->toBe('withdrawals')
            ->and($intentAlerts[0]['current'])->toBe(17)
            ->and($intentAlerts[0]['baseline'])->toBe(5.0)
            ->and($intentAlerts[0]['delta_pct'])->toBe(240)
            ->and($intentAlerts[0]['i18n']['key'])->toBe('ai.supervisor.anomaly.intent_delta')
            ->and(__('ai.supervisor.anomaly.intent_delta', $intentAlerts[0]['i18n']['params'], 'en'))->toContain('withdrawals conversations +240% since');
        expect(json_encode($result['alerts']))->not->toContain('spam');
        // The run is a supervisor decision row (provider none — arithmetic, no model call).
        $row = DB::table('ai_decisions')->where('id', $result['decision_id'])->first(['surface', 'task', 'action', 'provider']);
        expect($row->surface)->toBe('supervisor')->and($row->task)->toBe('anomaly_scan')->and($row->action)->toBe('anomaly_scan')->and($row->provider)->toBe('none');
    } finally {
        cleanupTenants([$tenantId]);
    }
});

it('FR-17 sentiment watch: a conversation whose latest classification is very negative becomes ONE feed item per 24 h; a later angry message in the same conversation adds none; spam and mild sentiment add none', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = supervisorScene();
    try {
        $other = anotherConversation($tenantId, 'calm');
        $spammy = anotherConversation($tenantId, 'spam');
        classifyRow($tenantId, $conversation->identifier(), 'withdrawals', -2, "clock_timestamp() - interval '20 minutes'");
        classifyRow($tenantId, $other->identifier(), 'deposits', -1, "clock_timestamp() - interval '20 minutes'");
        classifyRow($tenantId, $spammy->identifier(), 'spam', -2, "clock_timestamp() - interval '20 minutes'");

        $first = app(SentimentWatch::class)->run();
        expect(array_column($first['items'], 'conversation_id'))->toBe([$conversation->identifier()])
            ->and($first['items'][0]['sentiment'])->toBe(-2);

        classifyRow($tenantId, $conversation->identifier(), 'withdrawals', -2, "clock_timestamp() - interval '1 minute'");
        expect(app(SentimentWatch::class)->run()['items'])->toBe([]);
        expect(DB::table('ai_decisions')->where('surface', 'supervisor')->where('action', 'sentiment_watch')->count())->toBe(1);
    } finally {
        cleanupTenants([$tenantId]);
    }
});

it('FR-17 qa_prescore: samples yesterday\'s resolved tickets once each, scores against the FIXTURE rubric, writes an ADVISORY row (score, per-criterion, flags as categories) and touches NOTHING else — no agent score, no ticket field; a second run re-scores nothing', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = supervisorScene();
    try {
        $c = anotherConversation($tenantId, 'qa');
        $ticketId = (string) Str::uuid7();
        DB::table('tickets')->insert(['id' => $ticketId, 'tenant_id' => $tenantId, 'conversation_id' => $c->identifier(), 'customer_id' => $c->customer_id, 'number' => random_int(1, 2_000_000_000), 'status' => 'resolved', 'priority' => 'normal', 'source' => 'agent']);
        // resolved_at is DB-time physics (app cannot write it) — the platform role stamps yesterday for the fixture.
        asPlatformWorker(fn () => DB::connection('pgsql_platform')->update("UPDATE tickets SET resolved_at = date_trunc('day', clock_timestamp()) - interval '12 hours' WHERE id = ?", [$ticketId]));
        bindTenant($tenant);
        copilotCustomerMessage($tenantId, $c, '[FIXTURE] my withdrawal of 900 ETB took 3 days');
        DB::table('messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $c->identifier(), 'direction' => 'out', 'author_type' => 'agent', 'author_id' => $actor->identifier(), 'kind' => 'text', 'body' => 'Sorted now, sorry for the wait.', 'delivery_state' => 'sent']);
        $before = DB::table('tickets')->where('id', $ticketId)->first();
        $driver = copilotDriver(new ProviderResponse((string) json_encode(['score' => 72, 'criteria' => ['greeting' => 6, 'accuracy' => 9, 'resolution' => 8, 'tone' => 7, 'closing' => 5], 'flags' => ['no_greeting']]), 200, 40));

        $result = app(QaPrescore::class)->run();
        expect($result)->toBe(['paused' => null, 'scored' => 1, 'skipped' => 0, 'sampled' => 1]);
        $row = DB::table('ai_decisions')->where('ticket_id', $ticketId)->where('action', 'qa_prescore')->first(['surface', 'confidence', 'response_payload']);
        $payload = json_decode((string) $row->response_payload, true);
        expect($row->surface)->toBe('supervisor')->and((float) $row->confidence)->toBe(0.72)
            ->and($payload['score'])->toBe(72)->and($payload['criteria']['accuracy'])->toBe(9)->and($payload['flags'])->toBe(['no_greeting'])
            ->and($payload['advisory'])->toBeTrue()->and($payload['rubric_version'])->toBe('fixture-1');
        expect($driver->seen[0]['messages'][0]['content'])->toContain('accuracy (weight 3)');
        // Advisory: the ticket row is untouched and no score table exists to publish to.
        expect(DB::table('tickets')->where('id', $ticketId)->first())->toEqual($before);
        expect(DB::selectOne("SELECT count(*) AS n FROM information_schema.tables WHERE table_name ILIKE '%score%'")->n)->toBe(0);

        expect(app(QaPrescore::class)->run()['sampled'])->toBe(0);
    } finally {
        cleanupTenants([$tenantId]);
    }
});

it('FR-17 daily_digest: six bullets per manager (reports.supervisor.live holders only), in the recipient\'s locale, numbers ONLY from the facts — an invented number is rejected, the repair retried, then the TEMPLATE bullets stand (never silent)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = supervisorScene();
    try {
        $manager = inboxScopedActor('All', 'S09 Manager');
        grantPermissions($manager, ['tickets.view', 'reports.view', 'reports.supervisor.live'], 'S09 Manager Role');
        $manager->forceFill(['locale' => 'am'])->save();
        $agentOnly = inboxScopedActor('All', 'S09 Agent Only'); // no supervisor key → no digest

        // Yesterday's facts: 2 handoffs (no_grounding, customer_request), 1 AI resolve, 3 classifications.
        foreach (['no_grounding', 'customer_request'] as $reason) {
            platformDecisionRow($tenantId, ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'surface' => 'chatbot', 'task' => 'chatbot_turn', 'provider' => 'fake', 'model' => 'm', 'layer_hashes' => '{}', 'conversation_id' => $conversation->identifier(), 'action' => 'handoff', 'response_payload' => json_encode(['handoff_reason' => $reason])], "date_trunc('day', clock_timestamp()) - interval '10 hours'");
        }
        platformDecisionRow($tenantId, ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'surface' => 'chatbot', 'task' => 'chatbot_turn', 'provider' => 'fake', 'model' => 'm', 'layer_hashes' => '{}', 'conversation_id' => $conversation->identifier(), 'action' => 'resolve'], "date_trunc('day', clock_timestamp()) - interval '9 hours'");
        for ($i = 0; $i < 3; $i++) {
            classifyRow($tenantId, $conversation->identifier(), 'deposits', 0, "date_trunc('day', clock_timestamp()) - interval '8 hours'");
        }

        $good = "- ትናንት 0 ቲኬቶች ተከፍተዋል፣ 0 ተፈትተዋል\n- 1 ውይይት በረዳቱ ተፈትቷል\n- 2 ማስተላለፎች (no_grounding 1, customer_request 1)\n- 0 የኃላፊነት ጨዋታ ምልክቶች\n- 0 ማንቂያዎች\n- ዋና ዓላማዎች፦ deposits 3";
        $driver = copilotDriver(
            new ProviderResponse("- 7 tickets\n- fine", 10, 10),                 // not six lines → repair
            new ProviderResponse(str_replace('deposits 3', 'deposits 30', $good), 10, 10), // invented 30 → rejected → template
        );

        $result = app(DailyDigest::class)->run();
        expect(array_column($result['digests'], 'user_id'))->toBe([$manager->identifier()]);
        expect($result['digests'][0]['language'])->toBe('am')->and($result['digests'][0]['source'])->toBe('template');
        expect(count($driver->seen))->toBe(2, 'one repair retry, then the template');
        $payload = json_decode((string) DB::table('ai_decisions')->where('id', $result['digests'][0]['decision_id'])->value('response_payload'), true);
        expect($payload['bullets'])->toHaveCount(6)
            ->and($payload['bullets'][1])->toContain('1')->toContain('ረዳቱ')     // Amharic template, the fact's number
            ->and($payload['bullets'][2])->toContain('no_grounding 1')
            ->and($payload['facts']['ai_resolved'])->toBe(1)->and($payload['facts']['handoffs'])->toBe(2)->and($payload['facts']['top_intents'])->toBe(['deposits' => 3]);
        // The facts rode the SYSTEM layer (platform aggregates), never a user turn.
        expect($driver->seen[0]['messages'][0]['role'])->toBe('system')->and($driver->seen[0]['messages'][0]['content'])->toContain('digest_language: am');

        // A clean six-line answer is accepted as the model's.
        copilotDriver(new ProviderResponse($good, 10, 10));
        expect(app(DailyDigest::class)->forRecipient($manager)['source'])->toBe('model');
    } finally {
        cleanupTenants([$tenantId]);
    }
});

it('FS-01 (AC-09\'s last rung) + FR-18: with every provider of the chain DOWN the supervisor tasks PAUSE (recorded, no model call) and auto-resume on recovery; the supervisor kill switch pauses them the same way while the chatbot surface is untouched', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = supervisorScene();
    try {
        $driver = copilotDriver(new ProviderResponse('x', 1, 1));
        $health = app(ProviderHealth::class);

        $health->observe('fake-copilot', 'down');
        expect(app(AnomalyScan::class)->run()['paused'])->toBe('provider_down')
            ->and(app(SentimentWatch::class)->run()['paused'])->toBe('provider_down')
            ->and(app(QaPrescore::class)->run()['paused'])->toBe('provider_down')
            ->and(app(DailyDigest::class)->run()['paused'])->toBe('provider_down');
        expect(DB::table('ai_decisions')->where('surface', 'supervisor')->where('action', 'paused')->count())->toBe(3)
            ->and($driver->seen)->toBe([]);

        $health->observe('fake-copilot', 'healthy'); // recovery = resume, nothing to un-pause
        expect(app(AnomalyScan::class)->run()['paused'])->toBeNull();

        app(KillSwitches::class)->flip('supervisor', null, null, true, '[FIXTURE] pause the supervisor', $actor->identifier());
        expect(app(AnomalyScan::class)->run()['paused'])->toBe('killswitch')
            ->and(app(KillSwitches::class)->isOff('chatbot', null, null))->toBeFalse();
    } finally {
        cleanupTenants([$tenantId]);
    }
});

it('the supervisor FEED is the decision-log door filtered by surface (ai.decisions.view), payload pair still structurally absent', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = supervisorScene();
    try {
        grantPermissions($actor, ['tickets.view', 'ai.decisions.view'], 'S09 Feed Role');
        classifyRow($tenantId, $conversation->identifier(), 'withdrawals', -2, "clock_timestamp() - interval '5 minutes'");
        app(SentimentWatch::class)->run();

        $feed = $this->actingAs($actor, 'staff')->getJson('https://localhost/api/staff/ai/decisions?surface=supervisor');
        $feed->assertStatus(200);
        $items = $feed->json('data') ?? $feed->json('items') ?? [];
        expect($items)->toHaveCount(1)->and($items[0]['action'])->toBe('sentiment_watch')->and($items[0])->not->toHaveKey('response_payload');
    } finally {
        cleanupTenants([$tenantId]);
    }
});
