<?php

declare(strict_types=1);

use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Pipeline\InboundClassification;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Simulation\SimulationEngine;
use App\Domain\Ai\Simulation\ThresholdPublisher;
use App\Jobs\Ai\ClassifyInboundMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-11/US-06/AC-03/BR-07: SIMULATION-VS-REPLAY PARITY — the engine's
 * counts equal what the LIVE pipeline recorded under the same thresholds,
 * and equal what it records again under NEW thresholds on the same inputs;
 * publishing thresholds requires the simulation's token (BR-07).
 */
function parityFixture(): array
{
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    $tenantId = $tenant->identifier();
    registerFakeChannel();
    $customerId = (string) Str::uuid7();
    DB::table('customers')->insert(['id' => $customerId, 'tenant_id' => $tenantId, 'created_source' => 'telegram']);

    return [$tenantId, $customerId];
}

/** @param list<array{confidence: float, rg: float, spam: float}> $cases */
function runDay(string $tenantId, string $customerId, array $cases): array
{
    $driver = new FakeDriver(new FixturesOnlyEgress);
    app(ProviderRegistry::class)->swap('fake-sim', $driver);
    config(['ai.default_routing.fast' => [['provider' => 'fake-sim', 'model' => 'm']]]);

    $actual = [];
    foreach ($cases as $i => $case) {
        $driver->queue(new ProviderResponse((string) json_encode(['intent' => 'deposits', 'sentiment' => 0, 'urgency' => 1, 'spam' => $case['spam'], 'rg_risk' => $case['rg'], 'confidence' => $case['confidence']]), 100, 10));
        if ($case['rg'] >= 0.3) {
            $driver->queue(new ProviderResponse((string) json_encode(['rg_risk' => $case['rg'], 'evidence' => 'x']), 50, 5));
        }
        $conversation = anotherConversation($tenantId, 'sim'.$i.Str::random(4), $customerId);
        $messageId = (string) Str::uuid7();
        DB::table('messages')->insert(['id' => $messageId, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customerId, 'kind' => 'text', 'body' => '[FIXTURE] case '.$i, 'language' => 'en']);
        $eventId = (string) Str::uuid7();
        DB::table('events')->insert(['id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'message.received', 'payload' => json_encode(['tenant_id' => $tenantId, 'message_id' => $messageId, 'conversation_id' => $conversation->identifier(), 'customer_id' => $customerId, 'kind' => 'text'])]);
        (new ClassifyInboundMessage($eventId))->handle(app(InboundClassification::class));

        $row = DB::table('ai_decisions')->where('message_id', $messageId)->where('task', 'classify_message')->first();
        $actual[] = json_decode((string) $row->response_payload, true)['policy'];
    }

    return array_count_values($actual);
}

it('AC-03 (the two-halves form): live(T1) == sim(T1); then live(T2) == sim(T2) on identical inputs — the SAME RoutePolicy by construction, proven by execution', function (): void {
    $cases = [
        ['confidence' => 0.95, 'rg' => 0.05, 'spam' => 0.0],
        ['confidence' => 0.70, 'rg' => 0.05, 'spam' => 0.0],
        ['confidence' => 0.40, 'rg' => 0.05, 'spam' => 0.0],
        ['confidence' => 0.90, 'rg' => 0.95, 'spam' => 0.0],
        ['confidence' => 0.80, 'rg' => 0.05, 'spam' => 0.97],
        ['confidence' => 0.88, 'rg' => 0.05, 'spam' => 0.0],
        ['confidence' => 0.55, 'rg' => 0.05, 'spam' => 0.0],
    ];
    $today = now('UTC')->startOfDay()->toIso8601String();
    $normalize = function (array $counts): array {
        $counts = array_filter($counts);
        ksort($counts);

        return $counts;
    };

    // Half 1 — T1 in force (the defaults).
    [$tenantId, $customerId] = parityFixture();
    $t1 = ['rg_min' => 0.8, 'spam_min' => 0.9, 'handoff_below' => 0.6, 'auto_resolve_min' => 0.85];
    $live1 = runDay($tenantId, $customerId, $cases);
    $sim1 = app(SimulationEngine::class)->simulateDay($today, $t1);
    expect($normalize($sim1['outcomes']))->toBe($normalize($live1 + ['rg_override' => 0, 'spam' => 0, 'handoff_low_confidence' => 0, 'eligible_auto_resolve' => 0, 'eligible' => 0]));
    expect($live1['rg_override'] ?? 0)->toBe(1)->and($live1['spam'] ?? 0)->toBe(1)->and($live1['handoff_low_confidence'] ?? 0)->toBe(2);

    // Half 2 — T2 published (BR-07: with its simulation token), a FRESH
    // tenant replays the identical inputs; the prediction made from
    // tenant 1's decisions under T2 equals tenant 2's live outcomes.
    $predicted = app(SimulationEngine::class)->simulateDay($today, ['rg_min' => 0.8, 'spam_min' => 0.9, 'handoff_below' => 0.75, 'auto_resolve_min' => 0.92])['outcomes'];

    [$tenantId2, $customerId2] = parityFixture();
    $configs = app(ConfigVersions::class);
    $actor = (string) Str::uuid7();
    $t2 = ['rg_min' => 0.8, 'spam_min' => 0.9, 'handoff_below' => 0.75, 'auto_resolve_min' => 0.92, 'chatbot_enabled' => false];
    $v = $configs->draft('thresholds', 'default', $t2, $actor);
    app(ThresholdPublisher::class)->publish($v, SimulationEngine::tokenFor($today, array_intersect_key($t2, array_flip(['rg_min', 'spam_min', 'handoff_below', 'auto_resolve_min']))), $today, $actor);

    $live2 = runDay($tenantId2, $customerId2, $cases);
    expect($normalize($live2 + ['rg_override' => 0, 'spam' => 0, 'handoff_low_confidence' => 0, 'eligible_auto_resolve' => 0, 'eligible' => 0]))->toBe($normalize($predicted), 'the slider\'s prediction did not match the live replay');
    expect($live2['handoff_low_confidence'] ?? 0)->toBe(3, 'raising handoff_below to 0.75 should add exactly one handoff');
});

it('BR-07 AS PHYSICS: a thresholds draft publishes ONLY with the token of a simulation run for exactly its values', function (): void {
    [$tenantId] = parityFixture();
    $configs = app(ConfigVersions::class);
    $actor = (string) Str::uuid7();
    $today = now('UTC')->startOfDay()->toIso8601String();
    $values = ['rg_min' => 0.85, 'spam_min' => 0.9, 'handoff_below' => 0.5, 'auto_resolve_min' => 0.9];
    $v = $configs->draft('thresholds', 'default', $values, $actor);

    expect(fn () => app(ThresholdPublisher::class)->publish($v, 'not-a-real-token', $today, $actor))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(ThresholdPublisher::class)->publish($v, SimulationEngine::tokenFor($today, ['rg_min' => 0.8] + $values), $today, $actor))->toThrow(InvalidArgumentException::class);

    app(ThresholdPublisher::class)->publish($v, SimulationEngine::tokenFor($today, $values), $today, $actor);
    expect($configs->published('thresholds', 'default')?->id)->toBe($v);
});
