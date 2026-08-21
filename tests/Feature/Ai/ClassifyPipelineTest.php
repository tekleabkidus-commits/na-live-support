<?php

declare(strict_types=1);

use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Conversations\Inbound\BatchTailHint;
use App\Jobs\Ai\ClassifyInboundMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-05/06/07 + EC-01 + NFR-02: the classification pipeline as the FR-10
 * step-6 SUBSCRIBER — persistence-first by architecture (the consumer runs
 * on an event that only exists because the message committed), batch-tail
 * debounced, idempotent on the message's own decision (C-09), quarantine-
 * blind, and the FR-07 route recorded on the decision row.
 */
function classifyFixture(array $classification = []): array
{
    $tenant = createTenant();
    bindTenant($tenant);
    $tenantId = $tenant->identifier();

    $customerId = (string) Str::uuid7();
    DB::table('customers')->insert(['id' => $customerId, 'tenant_id' => $tenantId, 'created_source' => 'telegram']);
    $conversation = anotherConversation($tenantId, 'clf', $customerId);
    // Since step 9 the handoff_path route REALLY hands off (Doc 06 packet +
    // FR-28 template through the adapter) — the channel must exist.
    registerFakeChannel();

    $driver = new FakeDriver(new FixturesOnlyEgress);
    $payload = json_encode(array_merge([
        'intent' => 'withdrawals', 'sentiment' => -1, 'urgency' => 2, 'spam' => 0.0, 'rg_risk' => 0.05,
    ], $classification));
    $driver->queue(new ProviderResponse($payload, 400, 50));
    app(ProviderRegistry::class)->swap('fake-clf', $driver);
    config(['ai.default_routing.fast' => [['provider' => 'fake-clf', 'model' => 'clf-model']]]);

    return [$tenantId, $customerId, $conversation, $driver];
}

function inboundMessage(string $tenantId, string $conversationId, string $customerId, string $body, bool $quarantined = false): string
{
    $id = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
        'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customerId,
        'kind' => 'text', 'body' => $body, 'quarantined' => $quarantined,
    ]);

    return $id;
}

function classifyEvent(string $tenantId, string $conversationId, string $messageId, string $customerId): string
{
    $id = (string) Str::uuid7();
    DB::table('events')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'name' => 'message.received',
        'payload' => json_encode(['tenant_id' => $tenantId, 'message_id' => $messageId, 'conversation_id' => $conversationId, 'customer_id' => $customerId, 'kind' => 'text']),
    ]);

    return $id;
}

it('FR-06/FR-07: the consumer classifies the burst and records the decision row with the route — persistence untouched (NFR-02)', function (): void {
    [$tenantId, $customerId, $conversation, $driver] = classifyFixture();
    $messageId = inboundMessage($tenantId, $conversation->identifier(), $customerId, '[FIXTURE] my withdrawal is late');
    $eventId = classifyEvent($tenantId, $conversation->identifier(), $messageId, $customerId);

    (new ClassifyInboundMessage($eventId))->handle(app(App\Domain\Ai\Pipeline\InboundClassification::class));

    $decision = DB::table('ai_decisions')->where('message_id', $messageId)->first();
    expect($decision)->not->toBeNull()
        ->and($decision->task)->toBe('classify_message')
        ->and($decision->intent)->toBe('withdrawals')
        // Step 6's enablement default: chatbot unreachable -> handoff_path.
        ->and($decision->action)->toBe('handoff_path')
        ->and((float) $decision->rg_risk)->toBeLessThan(0.3)
        ->and(json_decode((string) $decision->layer_hashes, true))->toHaveKey('immutable');

    // NFR-02's other half: the message row is untouched by classification.
    expect(DB::table('messages')->where('id', $messageId)->value('body'))->toBe('[FIXTURE] my withdrawal is late');
});

it('EC-01: only the confirmed batch TAIL classifies — a superseded arrival is a silent no-op', function (): void {
    [$tenantId, $customerId, $conversation, $driver] = classifyFixture();
    $older = inboundMessage($tenantId, $conversation->identifier(), $customerId, '[FIXTURE] part one');
    $tail = inboundMessage($tenantId, $conversation->identifier(), $customerId, '[FIXTURE] part two, the real question');

    $hint = app(BatchTailHint::class);
    $hint->recordArrival($conversation->identifier(), $older);
    $hint->recordArrival($conversation->identifier(), $tail);
    expect($hint->confirm($conversation->identifier(), $tail))->toBeTrue();

    (new ClassifyInboundMessage(classifyEvent($tenantId, $conversation->identifier(), $older, $customerId)))
        ->handle(app(App\Domain\Ai\Pipeline\InboundClassification::class));
    expect(DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->count())->toBe(0, 'a superseded arrival classified');

    (new ClassifyInboundMessage(classifyEvent($tenantId, $conversation->identifier(), $tail, $customerId)))
        ->handle(app(App\Domain\Ai\Pipeline\InboundClassification::class));
    $decision = DB::table('ai_decisions')->where('message_id', $tail)->first();
    expect($decision)->not->toBeNull();

    // The burst reached the model as ONE block covering both parts.
    $request = json_decode((string) $decision->request_payload, true);
    expect($request['burst'])->toContain('part one')->toContain('part two');
});

it('C-09: a redelivered event changes nothing — one decision, however many deliveries', function (): void {
    [$tenantId, $customerId, $conversation, $driver] = classifyFixture();
    $messageId = inboundMessage($tenantId, $conversation->identifier(), $customerId, '[FIXTURE] hello');
    $eventId = classifyEvent($tenantId, $conversation->identifier(), $messageId, $customerId);

    (new ClassifyInboundMessage($eventId))->handle(app(App\Domain\Ai\Pipeline\InboundClassification::class));
    (new ClassifyInboundMessage($eventId))->handle(app(App\Domain\Ai\Pipeline\InboundClassification::class));

    // One classification AND one handoff (the route executed once) — the
    // redelivery added neither a second classification nor a second ticket.
    expect(DB::table('ai_decisions')->where('message_id', $messageId)->where('task', 'classify_message')->count())->toBe(1)
        ->and(DB::table('ai_decisions')->where('message_id', $messageId)->count())->toBe(2)
        ->and(DB::table('tickets')->where('conversation_id', $conversation->identifier())->count())->toBe(1);
});

it('FR-11 (Doc 04): a quarantined inbound gets NO classification — no fan-out means no AI', function (): void {
    [$tenantId, $customerId, $conversation, $driver] = classifyFixture();
    $messageId = inboundMessage($tenantId, $conversation->identifier(), $customerId, '[FIXTURE] blocked convo msg', quarantined: true);
    $eventId = classifyEvent($tenantId, $conversation->identifier(), $messageId, $customerId);

    (new ClassifyInboundMessage($eventId))->handle(app(App\Domain\Ai\Pipeline\InboundClassification::class));

    expect(DB::table('ai_decisions')->where('message_id', $messageId)->count())->toBe(0)
        ->and($driver->seen)->toBe([], 'a quarantined message reached the provider');
});

it('T-06: the two-stage RG confirmation runs ONLY past the screening floor, and its precision prompt outranks the screen', function (): void {
    [$tenantId, $customerId, $conversation, $driver] = classifyFixture(['rg_risk' => 0.55, 'intent' => 'rg_risk']);
    // Stage two's scripted verdict: the precision prompt CLEARS the case.
    $driver->queue(new ProviderResponse((string) json_encode(['rg_risk' => 0.1, 'evidence' => 'service complaint']), 100, 20));

    $messageId = inboundMessage($tenantId, $conversation->identifier(), $customerId, '[FIXTURE] angry about a delay, not risk');
    (new ClassifyInboundMessage(classifyEvent($tenantId, $conversation->identifier(), $messageId, $customerId)))
        ->handle(app(App\Domain\Ai\Pipeline\InboundClassification::class));

    $decision = DB::table('ai_decisions')->where('message_id', $messageId)->where('task', 'classify_message')->first();
    // Calls 1 and 2 are the screen and the confirmation; the third (since
    // step 9) is the handoff summary the cleared case then routes to.
    expect((float) $decision->rg_risk)->toBe(0.1, 'stage two did not outrank the screening signal')
        ->and(count($driver->seen))->toBe(3, 'the confirmation stage never ran')
        ->and(json_encode($driver->seen[1]))->toContain('[FIXTURE] angry about a delay');
});

it('FR-07 (filed interpretation, S09 step 14): a second customer message while the handoff ticket waits UNASSIGNED does NOT hand off again — ONE ticket, the classification rides as copilot_context, no exception (the NFR-02 perf leg found the crash)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    // A fresh conversation with no ticket; chatbot OFF (copilotScene's default record).
    $customerId = (string) Str::uuid7();
    DB::table('customers')->insert(['id' => $customerId, 'tenant_id' => $tenantId, 'created_source' => 'telegram', 'language' => 'en']);
    $c = anotherConversation($tenantId, 'twice', $customerId);
    $classification = new ProviderResponse((string) json_encode(['intent' => 'deposits', 'sentiment' => 0, 'urgency' => 1, 'spam' => 0.0, 'rg_risk' => 0.05, 'confidence' => 0.8]), 10, 10);
    copilotDriver($classification, new ProviderResponse('[FIXTURE] summary.', 10, 10), $classification, $classification);

    foreach (['[FIXTURE] my deposit is missing', '[FIXTURE] hello? anyone there?'] as $body) {
        $id = (string) Str::uuid7();
        DB::table('messages')->insert(['id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $c->identifier(), 'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customerId, 'kind' => 'text', 'body' => $body, 'language' => 'en']);
        $eventId = (string) Str::uuid7();
        DB::table('events')->insert(['id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'message.received', 'payload' => json_encode(['tenant_id' => $tenantId, 'message_id' => $id, 'conversation_id' => $c->identifier(), 'customer_id' => $customerId, 'kind' => 'text'])]);
        (new ClassifyInboundMessage($eventId))->handle(app(App\Domain\Ai\Pipeline\InboundClassification::class));
    }

    expect(DB::table('tickets')->where('conversation_id', $c->identifier())->count())->toBe(1, 'a second handoff duplicated the ticket');
    $routes = DB::table('ai_decisions')->where('conversation_id', $c->identifier())->where('task', 'classify_message')->orderBy('created_at')->pluck('action')->all();
    expect($routes)->toBe(['handoff_path', 'copilot_context']);
});
