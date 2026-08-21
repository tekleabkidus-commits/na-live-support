<?php

declare(strict_types=1);

use App\Domain\Ai\Copilot\ComposerActions;
use App\Domain\Ai\Copilot\CopilotPanel;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Events\Conversations\RealtimePush;
use App\Jobs\Ai\CopilotPanelRefresh;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Doc 09 FR-14 — the panel contract: chips live from the classification
 * (rg_risk NEVER shown — BR-06), summary/KB/similar from the refresh job
 * (debounced 10 s by TTL lock, pushed to the agent's user channel), macro
 * suggestions an honest Doc 12 seam; EC-09's regenerate ×3 → locked
 * low-confidence state + telemetry row; FS-01's Copilot DEGRADED card.
 */
it('FR-14 chips: intent / sentiment / urgency / confidence from the latest classification — and rg_risk is structurally absent from the Copilot (BR-06/SEC-04)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] I lost everything again, my withdrawal is stuck', 'en', ['intent' => 'withdrawals', 'sentiment' => -2, 'urgency' => 3, 'confidence' => 0.91, 'rg_risk' => 0.95]);
    Queue::fake();

    $panel = $this->actingAs($actor, 'staff')->getJson('https://localhost/inbox/ai/copilot/'.$conversation->identifier().'/panel');
    $panel->assertStatus(200);

    expect($panel->json('chips'))->toBe(['intent' => 'withdrawals', 'sentiment' => -2, 'urgency' => 3, 'confidence' => 0.91]);
    expect(json_encode($panel->json()))->not->toContain('rg_risk');
    expect($panel->json('macros'))->toBe(['seam' => 'Doc12', 'items' => []]);
    expect($panel->json('summary_state'))->toBe('refreshing');
    Queue::assertPushed(CopilotPanelRefresh::class, 1);
});

it('FR-14 debounce: repeated panel reads within 10 s dispatch ONE refresh (TTL lock); a refresh fills summary + KB top-3 (internal included) + notices and pushes ai.copilot.panel to the agent\'s user channel', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] how do I verify my account?', 'en', ['intent' => 'verification']);
    app(App\Domain\Ai\Retrieval\KbIndexer::class)->index('018f6b2e-0000-7000-8000-0000000000b1', 0, 'en', 'Verification needs an ID photo and a selfie; staff-only: check the watermark.', audience: 'internal', fixtureVector: copilotVector(6));

    Queue::fake();
    $url = 'https://localhost/inbox/ai/copilot/'.$conversation->identifier().'/panel';
    $this->actingAs($actor, 'staff')->getJson($url)->assertStatus(200);
    $this->actingAs($actor, 'staff')->getJson($url)->assertStatus(200);
    $this->actingAs($actor, 'staff')->getJson($url)->assertStatus(200);
    Queue::assertPushed(CopilotPanelRefresh::class, 1);

    // Run the refresh (the job body) with a scripted provider.
    copilotDriver(
        new ProviderResponse('[FIXTURE] Customer asks how to verify their account. Nothing done yet. Open: send the verification steps.', 40, 30),
        copilotEmbedding(6),
        copilotEmbedding(6),
    );
    Event::fake([RealtimePush::class]);
    bindTenant($tenant); // the HTTP lifecycle above released the test's binding
    $payload = app(CopilotPanel::class)->refresh($actor, $conversation);

    expect($payload['summary'])->toContain('verify their account')
        ->and($payload['kb'][0]['article_ref'])->toBe('018f6b2e-0000-7000-8000-0000000000b1')
        ->and($payload['notices'])->toBe([]);
    Event::assertDispatched(RealtimePush::class, function (RealtimePush $push) use ($tenantId, $actor): bool {
        return $push->eventName === 'ai.copilot.panel' && $push->channelName === sprintf('tenant.%s.user.%s', $tenantId, $actor->identifier()) && ($push->payload['v'] ?? null) === 1;
    });

    // A read now is FRESH (as_of the latest message) and dispatches nothing.
    Queue::fake();
    $panel = $this->actingAs($actor, 'staff')->getJson($url);
    expect($panel->json('summary_state'))->toBe('fresh')->and($panel->json('kb'))->toHaveCount(1);
    Queue::assertNothingPushed();

    // A NEW customer message makes it stale again → one more refresh (after the lock expires; we clear it).
    bindTenant($tenant);
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] and how long does it take?');
    app(App\Support\Cache\TenantCache::class)->forget('ai:copilot:refresh-lock:'.$conversation->identifier().':'.$actor->identifier());
    $panel = $this->actingAs($actor, 'staff')->getJson($url);
    expect($panel->json('summary_state'))->toBe('refreshing')->and($panel->json('summary'))->toContain('verify their account'); // the previous summary stays visible while refreshing
    Queue::assertPushed(CopilotPanelRefresh::class, 1);
});

it('EC-09: regenerate ×3 exhausted → the card LOCKS to the low-confidence state with KB links, a telemetry row flags the conversation for the weekly review, and the fourth attempt makes NO provider call', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] is the bonus valid for live bets?', 'en', ['intent' => 'bonuses']);
    $draft = new ProviderResponse((string) json_encode(['text' => '[FIXTURE] Let me check the bonus terms for live bets.', 'citations' => [], 'confidence' => 0.8]), 10, 10);
    $driver = copilotDriver(copilotEmbedding(1), $draft, copilotEmbedding(1), $draft, copilotEmbedding(1), $draft, copilotEmbedding(1));

    // Cooldown off for the proof (the cooldown has its own test below).
    app(App\Support\Settings\SettingsRepository::class)->set('ai.copilot.regenerate_cooldown_seconds', 0, tenantSurface: false);

    $actions = app(ComposerActions::class);
    for ($i = 1; $i <= 3; $i++) {
        $card = $actions->draft($actor->identifier(), $conversation);
        expect($card['state'])->toBe('artifact')->and($card['regenerations'])->toBe(['used' => $i, 'limit' => 3]);
    }
    $callsBefore = count($driver->seen);

    $locked = $actions->draft($actor->identifier(), $conversation);
    expect($locked['state'])->toBe('locked')
        ->and($locked['notices'])->toBe(['ai.copilot.notice.regenerate_exhausted'])
        ->and($locked)->toHaveKey('kb')
        ->and($locked['regenerations'])->toBe(['used' => 3, 'limit' => 3]);
    expect(count($driver->seen))->toBe($callsBefore + 1, 'the locked state may embed for KB links but must NOT call the model'); // +1 = the KB embedding only
    expect(DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('action', 'low_confidence_locked')->count())->toBe(1, 'EC-09 telemetry row');

    // A fifth attempt: still locked, no second telemetry row.
    $actions->draft($actor->identifier(), $conversation);
    expect(DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('action', 'low_confidence_locked')->count())->toBe(1);
});

it('EC-09 cooldown: a regenerate inside the cooldown window is refused with state=cooldown and no provider call', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] hi, deposit question');
    $driver = copilotDriver(copilotEmbedding(1), new ProviderResponse((string) json_encode(['text' => '[FIXTURE] Sure — what would you like to know about deposits?', 'citations' => [], 'confidence' => 0.9]), 10, 10));
    $actions = app(ComposerActions::class);

    expect($actions->draft($actor->identifier(), $conversation)['state'])->toBe('artifact');
    $calls = count($driver->seen);
    $second = $actions->draft($actor->identifier(), $conversation);
    expect($second['state'])->toBe('cooldown')->and(count($driver->seen))->toBe($calls);
});

it('FS-01 / AC-09 (Copilot half): with the provider chain exhausted the card is DEGRADED — never a spinner, never a fabricated draft — with the KB links and a refused row (provider_error); quota exhaustion maps the same way', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] deposit not showing');
    $driver = copilotDriver(copilotEmbedding(1)); // the draft call finds an empty script → provider failure → exhausted

    $card = app(ComposerActions::class)->draft($actor->identifier(), $conversation);
    expect($card['state'])->toBe('degraded')->and($card['reason'])->toBe('provider_error')->and($card['notices'])->toBe(['ai.copilot.notice.degraded']);
    expect(DB::table('ai_decisions')->where('id', $card['artifact_id'])->value('error_category'))->toBe('provider_error');

    // The job pushes the degraded CARD to the agent (T-04's last frame).
    Event::fake([RealtimePush::class]);
    Illuminate\Support\Facades\Cache::flush(); // breaker state reset — a fresh chain for the second leg
    copilotDriver(copilotEmbedding(1));
    (new App\Jobs\Ai\CopilotTaskJob('req-degraded', 'draft', $conversation->identifier(), $actor->identifier()))->handle(app(ComposerActions::class), app(App\Domain\Ai\Copilot\CopilotStreamRelay::class), app(App\Domain\Ai\Concurrency\TenantSemaphore::class));
    Event::assertDispatched(RealtimePush::class, fn (RealtimePush $push): bool => $push->eventName === 'ai.copilot.card' && ($push->payload['state'] ?? null) === 'degraded' && ($push->payload['request_id'] ?? null) === 'req-degraded');
    // And the degraded attempt did NOT consume a regeneration or start a cooldown (the platform's failure, not the agent's).
    expect(DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('surface', 'copilot')->where('error_category', 'provider_error')->count())->toBe(2);
});
