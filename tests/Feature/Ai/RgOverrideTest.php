<?php

declare(strict_types=1);

use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Pipeline\InboundClassification;
use App\Domain\Ai\Pipeline\TurnDecision;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Rg\RgCalibration;
use App\Domain\Customers\Rg\RgFlagService;
use App\Jobs\Ai\ClassifyInboundMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-13/BR-03/BR-04/AC-04 + the [D09] closes (Doc 08 FR-14's source=ai
 * PRODUCER; Doc 08 EC-06's calibration CONSUMPTION): the RG override through
 * the REAL pipeline — flag via Doc 08's door, template byte-verified and
 * structurally generation-free (N-10), RG handoff via Doc 06's door,
 * preemption order, suppression engagement, calibration read-back.
 * Sabotages #5 (preemption) and #6 (template -> generated) target this file.
 */
function rgFixture(float $screen = 0.95, float $confirm = 0.95, string $language = 'en'): array
{
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    $tenantId = $tenant->identifier();
    registerFakeChannel();

    $customerId = (string) Str::uuid7();
    DB::table('customers')->insert(['id' => $customerId, 'tenant_id' => $tenantId, 'created_source' => 'telegram', 'language' => $language]);
    $conversation = anotherConversation($tenantId, 'rg', $customerId);

    $driver = new FakeDriver(new FixturesOnlyEgress);
    $driver->queue(new ProviderResponse((string) json_encode(['intent' => 'rg_risk', 'sentiment' => -2, 'urgency' => 3, 'spam' => 0, 'rg_risk' => $screen]), 400, 50));
    $driver->queue(new ProviderResponse((string) json_encode(['rg_risk' => $confirm, 'evidence' => 'chasing losses']), 100, 20));
    app(ProviderRegistry::class)->swap('fake-rg', $driver);
    config(['ai.default_routing.fast' => [['provider' => 'fake-rg', 'model' => 'rg-model']], 'ai.default_routing.strong' => [['provider' => 'fake-rg', 'model' => 'rg-model']]]);

    return [$tenantId, $customerId, $conversation, $driver];
}

function rgInbound(string $tenantId, string $conversationId, string $customerId, string $body, string $language = 'en'): string
{
    $id = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
        'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customerId,
        'kind' => 'text', 'body' => $body, 'language' => $language,
    ]);
    $eventId = (string) Str::uuid7();
    DB::table('events')->insert([
        'id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'message.received',
        'payload' => json_encode(['tenant_id' => $tenantId, 'message_id' => $id, 'conversation_id' => $conversationId, 'customer_id' => $customerId, 'kind' => 'text']),
    ]);

    return $eventId;
}

it('FR-13 + [D09] FR-14 close: the override writes the flag THROUGH Doc 08\'s door — source=ai, NULL actor by CHECK, MANDATORY excerpt', function (): void {
    [$tenantId, $customerId, $conversation, $driver] = rgFixture();
    $eventId = rgInbound($tenantId, $conversation->identifier(), $customerId, '[FIXTURE] I cannot stop betting, I lost my rent money');

    (new ClassifyInboundMessage($eventId))->handle(app(InboundClassification::class));

    $history = DB::table('rg_flag_history')->where('customer_id', $customerId)->where('action', 'set')->first();
    expect($history)->not->toBeNull('no flag was written — the producer did not fire')
        ->and($history->source)->toBe('ai')
        ->and($history->actor_id)->toBeNull()
        ->and(json_decode((string) $history->excerpt_ref, true))->toHaveKeys(['message_id', 'conversation_id', 'decision_id'])
        ->and((bool) DB::table('customers')->where('id', $customerId)->value('rg_flag'))->toBeTrue();

    // FR-15: the suppression contracts engage — Doc 08's own event fired.
    expect(DB::table('outbox')->where('name', 'customer.rg_flag.set')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'ai.rg.flagged')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'ai.handoff.created')->count())->toBe(1);
});

it('BR-03/AC-04: the customer receives the operator template BYTE FOR BYTE — en and am — never a generated word', function (): void {
    foreach (['en', 'am'] as $language) {
        [$tenantId, $customerId, $conversation, $driver] = rgFixture(language: $language);
        $eventId = rgInbound($tenantId, $conversation->identifier(), $customerId, '[FIXTURE] please block me forever', $language);

        (new ClassifyInboundMessage($eventId))->handle(app(InboundClassification::class));

        $sent = DB::table('messages')->where('conversation_id', $conversation->identifier())->where('direction', 'out')->first();
        expect($sent)->not->toBeNull("no template was sent ({$language})")
            ->and($sent->author_type)->toBe('ai')
            ->and($sent->body)->toBe(__('ai.rg.template', [], $language), "the {$language} body is not the template byte for byte (BR-03)")
            ->and(count($driver->seen))->toBe(2, 'the RG send path spent a generation call — only classify + confirm may touch the provider');
    }
});

it('N-10 STRUCTURAL: the RG override cannot reach the generation service — no orchestrator, no provider, no driver in its source', function (): void {
    $source = (string) php_strip_whitespace(app_path('Domain/Ai/Rg/RgOverride.php'));

    foreach (['AiOrchestrator', 'ProviderRegistry', 'ProviderDriver', 'OpenAiCompatibleClient', 'LayeredAssembly'] as $forbidden) {
        expect(str_contains($source, $forbidden))->toBeFalse("RgOverride references {$forbidden} — the RG branch can reach generation (N-10)");
    }
});

it('FR-13/AC-04 PREEMPTION: rg_override outranks an open assigned ticket and any flow state — FR-07 decides RG FIRST (sabotage #5 subject)', function (): void {
    [$tenantId, $customerId, $conversation] = rgFixture();

    // An open ASSIGNED ticket would otherwise route to copilot_context.
    $agent = ticketActor(['tickets.view']);
    DB::table('tickets')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(),
        'customer_id' => $customerId, 'number' => random_int(1, 2_000_000_000), 'status' => 'open',
        'priority' => 'normal', 'source' => 'agent', 'assignee_id' => $agent->identifier(),
    ]);

    $decide = app(TurnDecision::class);
    expect($decide->decide($conversation->identifier(), 'fakechan', null, 0.95))->toBe('rg_override')
        ->and($decide->decide($conversation->identifier(), 'fakechan', null, 0.2))->toBe('copilot_context');
});

it('FR-18: a PUBLISHED guardrails rg_template overrides the seeded wording — presence stays, wording is operator content', function (): void {
    [$tenantId, $customerId, $conversation] = rgFixture();
    $configs = app(ConfigVersions::class);
    $actor = (string) Str::uuid7();
    $v = $configs->draft('guardrails', 'default', ['rg_template' => ['en' => '[FIXTURE] Operator RG wording — we are here for you.']], $actor);
    $configs->publish($v, $actor);

    expect(app(App\Domain\Ai\Rg\RgOverride::class)->templateFor('en'))->toBe('[FIXTURE] Operator RG wording — we are here for you.')
        ->and(app(App\Domain\Ai\Rg\RgOverride::class)->templateFor('am'))->toBe(__('ai.rg.template', [], 'am'));
});

it('C-09: a redelivered event fires the override ONCE — one flag, one template, one ticket', function (): void {
    [$tenantId, $customerId, $conversation] = rgFixture();
    $eventId = rgInbound($tenantId, $conversation->identifier(), $customerId, '[FIXTURE] I want self exclusion now');

    (new ClassifyInboundMessage($eventId))->handle(app(InboundClassification::class));
    (new ClassifyInboundMessage($eventId))->handle(app(InboundClassification::class));

    expect(DB::table('rg_flag_history')->where('customer_id', $customerId)->count())->toBe(1)
        ->and(DB::table('messages')->where('conversation_id', $conversation->identifier())->where('direction', 'out')->count())->toBe(1)
        ->and(DB::table('tickets')->where('conversation_id', $conversation->identifier())->where('source', 'ai_handoff')->count())->toBe(1);
});

it('[D09] EC-06 close: calibration CONSUMES Doc 08\'s stored clear-reasons — the review queue pairs the AI fire with the human clear', function (): void {
    [$tenantId, $customerId, $conversation] = rgFixture();
    $eventId = rgInbound($tenantId, $conversation->identifier(), $customerId, '[FIXTURE] I lost my salary again and cannot stop');
    (new ClassifyInboundMessage($eventId))->handle(app(InboundClassification::class));

    $reviewer = ticketActor(['customers.rg_flag.set']);
    app(RgFlagService::class)->clear($customerId, '[FIXTURE] spoke to the customer — venting about a delay, no risk', $reviewer->identifier());

    $queue = app(RgCalibration::class)->reviewQueue();
    $row = collect($queue)->firstWhere('customer_id', $customerId);

    expect($row)->not->toBeNull()
        ->and($row['clear_reason'])->toContain('venting about a delay')
        ->and($row['ai_score'])->toBe(0.95)
        ->and($row['decision_id'])->not->toBeNull();
});
