<?php

declare(strict_types=1);

use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Pipeline\FlowStateProvider;
use App\Domain\Ai\Pipeline\InboundClassification;
use App\Domain\Ai\Providers\FakeDriver;
use App\Domain\Ai\Providers\ProviderRegistry;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Retrieval\KbIndexer;
use App\Jobs\Ai\AiOutcomeEnrichmentConsumer;
use App\Jobs\Ai\ClassifyInboundMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-08/09/12, EC-02/04/05/08/11/12, FS-01/FS-02, AC-02, AC-09's customer
 * side, T-08's join — THE CHATBOT TURN through the real pipeline (consumer →
 * classify → decide → turn), scripted provider, real Doc 04/06 doors.
 * Sabotage #8 (citation requirement dropped) targets the AC-02 case.
 */
function turnTenant(bool $chatbotEnabled = true): array
{
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    $tenantId = $tenant->identifier();
    Cache::flush();
    registerFakeChannel();

    $actor = (string) Str::uuid7();
    $configs = app(ConfigVersions::class);
    $v = $configs->draft('thresholds', 'default', ['chatbot_enabled' => $chatbotEnabled, 'rg_min' => 0.8, 'handoff_below' => 0.3, 'auto_resolve_min' => 0.85, 'spam_min' => 0.9], $actor);
    $configs->publish($v, $actor);

    $customerId = (string) Str::uuid7();
    DB::table('customers')->insert(['id' => $customerId, 'tenant_id' => $tenantId, 'created_source' => 'telegram', 'language' => 'en']);
    $conversation = anotherConversation($tenantId, 'turn', $customerId);

    return [$tenantId, $customerId, $conversation];
}

function turnDriver(array $classification, ProviderResponse|Throwable ...$turnResponses): FakeDriver
{
    $driver = new FakeDriver(new FixturesOnlyEgress);
    $driver->queue(new ProviderResponse((string) json_encode(array_merge(['intent' => 'deposits', 'sentiment' => 0, 'urgency' => 1, 'spam' => 0.0, 'rg_risk' => 0.05, 'confidence' => 0.8], $classification)), 400, 50));
    foreach ($turnResponses as $response) {
        $driver->queue($response);
    }
    app(ProviderRegistry::class)->swap('fake-turn', $driver);
    config([
        'ai.default_routing.fast' => [['provider' => 'fake-turn', 'model' => 'm']],
        'ai.default_routing.strong' => [['provider' => 'fake-turn', 'model' => 'm']],
        'ai.default_routing.embeddings' => [['provider' => 'fake-turn', 'model' => 'e']],
    ]);

    return $driver;
}

function turnInbound(string $tenantId, object $conversation, string $customerId, string $body, string $language = 'en'): string
{
    $id = (string) Str::uuid7();
    DB::table('messages')->insert(['id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customerId, 'kind' => 'text', 'body' => $body, 'language' => $language]);
    $eventId = (string) Str::uuid7();
    DB::table('events')->insert(['id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'message.received', 'payload' => json_encode(['tenant_id' => $tenantId, 'message_id' => $id, 'conversation_id' => $conversation->identifier(), 'customer_id' => $customerId, 'kind' => 'text'])]);
    (new ClassifyInboundMessage($eventId))->handle(app(InboundClassification::class));

    return $id;
}

function modelAction(string $action, array $extra = []): ProviderResponse
{
    return new ProviderResponse((string) json_encode(['action' => $action] + $extra), 500, 60);
}

function outboundBodies(object $conversation): array
{
    return DB::table('messages')->where('conversation_id', $conversation->identifier())->where('direction', 'out')->orderBy('created_at')->pluck('body')->all();
}

const KB_DEP = '018f6b2e-0000-7000-8000-0000000000d1';

function unitVector(int $hot): array
{
    $v = array_fill(0, 1024, 0.0);
    $v[$hot] = 1.0;

    return $v;
}

it('FR-08/FR-10/AC-02 (positive): a grounded answer goes out WITH its citation rendered per channel capability, and the decision logs action=answer', function (): void {
    [$tenantId, $customerId, $conversation] = turnTenant();
    app(KbIndexer::class)->index(KB_DEP, 0, 'en', 'Deposits with telebirr settle within 30 minutes.', fixtureVector: unitVector(1));
    $driver = turnDriver([], new ProviderResponse('', 0, 0, [], [unitVector(1)]), modelAction('answer', ['text' => 'Deposits with telebirr settle within 30 minutes.', 'citations' => [KB_DEP]]));

    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] how long does a telebirr deposit take?');

    $out = outboundBodies($conversation);
    expect($out)->toHaveCount(1)
        ->and($out[0])->toContain('settle within 30 minutes')
        ->and($out[0])->toContain('📄 '.KB_DEP);
    expect(DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('task', 'chatbot_turn')->value('action'))->toBe('answer');
});

it('AC-02/BR-02 (sabotage #8 target): a money question with NO retrieval hit — the model answers uncited twice — EC-05 retry, then no_grounding handoff; the customer NEVER sees the uncited answer', function (): void {
    [$tenantId, $customerId, $conversation] = turnTenant();
    $driver = turnDriver([],
        new ProviderResponse('', 0, 0, [], [unitVector(7)]),
        modelAction('answer', ['text' => 'The maximum withdrawal fee is 2%.']),                        // uncited attempt 1
        modelAction('answer', ['text' => 'The fee is 2%, guaranteed.', 'citations' => ['kb-invented']]), // invented citation attempt 2
        new ProviderResponse('Summary sentence one. Summary sentence two. Summary sentence three.', 50, 30),
    );

    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] what is the maximum withdrawal fee policy?');

    $out = outboundBodies($conversation);
    expect($out)->toHaveCount(1, 'expected exactly the handoff template')
        ->and($out[0])->toBe(__('ai.handoff.template', [], 'en'))
        ->and(implode(' ', $out))->not->toContain('2%');
    $ticket = DB::table('tickets')->where('conversation_id', $conversation->identifier())->where('source', 'ai_handoff')->first();
    expect($ticket)->not->toBeNull();
    $packet = json_decode((string) DB::table('ticket_handoff_packets')->where('ticket_id', $ticket->id)->value('payload'), true);
    expect($packet['suggested']['reasons'] ?? $packet['suggested_reasons'] ?? [])->toContain('no_grounding');
    // EC-05: both attempts logged with error_category invalid_action.
    expect(DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('error_category', 'invalid_action')->count())->toBe(2);
});

it('EC-05: an out-of-enum action → ONE repair retry → still invented → handoff invalid_action, both logged', function (): void {
    [$tenantId, $customerId, $conversation] = turnTenant();
    turnDriver([], new ProviderResponse('', 0, 0, [], [unitVector(3)]),
        new ProviderResponse('{"action":"transfer_funds","amount":5000}', 500, 20),
        new ProviderResponse('{"action":"refund_now"}', 500, 20),
        new ProviderResponse('One. Two. Three.', 10, 10));

    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] refund my stake now');

    $handoff = DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('action', 'handoff')->first();
    expect(json_decode((string) $handoff->response_payload, true)['handoff_reason'])->toBe('invalid_action')
        ->and(DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('error_category', 'invalid_action')->count())->toBe(2);
});

it('FR-12: customer requests a human → the FR-11 packet via Doc 06\'s door, the FR-28 template, ai.handoff.created, and T-08\'s join: a later ticket.reopened ENRICHES exactly that decision (countersign item 3)', function (): void {
    [$tenantId, $customerId, $conversation] = turnTenant();
    turnDriver([], new ProviderResponse('', 0, 0, [], [unitVector(2)]), modelAction('handoff', ['reason' => 'customer_request']), new ProviderResponse('One. Two. Three.', 10, 10));

    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] I want to talk to a real person');

    $ticket = DB::table('tickets')->where('conversation_id', $conversation->identifier())->where('source', 'ai_handoff')->first();
    expect($ticket)->not->toBeNull()
        ->and(outboundBodies($conversation))->toBe([__('ai.handoff.template', [], 'en')])
        ->and(DB::table('outbox')->where('name', 'ai.handoff.created')->count())->toBe(1);

    $decision = DB::table('ai_decisions')->where('ticket_id', $ticket->id)->first();
    expect($decision)->not->toBeNull('the handoff decision does not carry ticket_id — T-08\'s join key is missing');

    // The join assertion: a ticket.reopened event for THIS ticket enriches THIS decision.
    $eventId = (string) Str::uuid7();
    DB::table('events')->insert(['id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'ticket.reopened', 'payload' => json_encode(['tenant_id' => $tenantId, 'ticket_id' => $ticket->id, 'conversation_id' => $conversation->identifier(), 'from_status' => 'resolved', 'reopened_count' => 1, 'assignee_id' => null, 'actor_type' => 'customer', 'actor_id' => $customerId])]);
    (new AiOutcomeEnrichmentConsumer($eventId))->handle(app(App\Domain\Ai\DecisionLog\OutcomeEnrichment::class));

    expect(DB::table('ai_decisions')->where('id', $decision->id)->value('outcome'))->toBe('resolved_then_reopened')
        ->and(DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->whereNull('ticket_id')->whereNotNull('outcome')->count())->toBe(0, 'enrichment touched a decision that does not own the ticket');
});

it('FR-09/EC-08: resolve sends the closing text + ai.resolved; a reply reopens with post_resolution context; the THIRD reply hands off post_resolution_loop', function (): void {
    [$tenantId, $customerId, $conversation] = turnTenant();
    $driver = turnDriver([],
        new ProviderResponse('', 0, 0, [], [unitVector(4)]), modelAction('resolve', ['closing_text' => 'Glad that solved it!']),
    );
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] thanks that fixed it');
    expect(outboundBodies($conversation))->toBe(['Glad that solved it!'])
        ->and(DB::table('outbox')->where('name', 'ai.resolved')->count())->toBe(1);

    // Reply 1 after resolve: the turn runs WITH post_resolution context and resolves again.
    $driver->queue(new ProviderResponse((string) json_encode(['intent' => 'deposits', 'sentiment' => 0, 'urgency' => 1, 'spam' => 0, 'rg_risk' => 0.05, 'confidence' => 0.8]), 400, 50));
    $driver->queue(new ProviderResponse('', 0, 0, [], [unitVector(4)]));
    $driver->queue(modelAction('resolve', ['closing_text' => 'Great, all set.']));
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] actually one more thing, also fine now');
    $secondTurn = DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('task', 'chatbot_turn')->orderByDesc('created_at')->first();
    expect(json_decode((string) $secondTurn->request_payload, true)['messages'][0]['content'])->toContain('post_resolution');

    // Reply 2 after the second resolve: ×2 reached → mandatory handoff, no model call.
    $driver->queue(new ProviderResponse((string) json_encode(['intent' => 'deposits', 'sentiment' => 0, 'urgency' => 1, 'spam' => 0, 'rg_risk' => 0.05, 'confidence' => 0.8]), 400, 50));
    $driver->queue(new ProviderResponse('One. Two. Three.', 10, 10));
    $before = count($driver->seen);
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] and another thing');
    $last = DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('action', 'handoff')->first();
    expect(json_decode((string) $last->response_payload, true)['handoff_reason'])->toBe('post_resolution_loop');
});

it('EC-04: betting advice → the deflection TEMPLATE (never generated), third strike → blocked_topic_persistence handoff', function (): void {
    [$tenantId, $customerId, $conversation] = turnTenant();
    $driver = turnDriver([]);
    $classify = fn () => $driver->queue(new ProviderResponse((string) json_encode(['intent' => 'betting_rules', 'sentiment' => 0, 'urgency' => 0, 'spam' => 0, 'rg_risk' => 0.05, 'confidence' => 0.8]), 400, 50));

    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] what should I bet on this weekend?');
    $classify();
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] which team will win tonight, give me a tip');
    $classify();
    $driver->queue(new ProviderResponse('One. Two. Three.', 10, 10));
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] come on, who should I bet on?');

    $out = outboundBodies($conversation);
    expect($out[0])->toBe(__('ai.deflection.template', [], 'en'))
        ->and($out[1])->toBe(__('ai.deflection.template', [], 'en'))
        ->and($out[2])->toBe(__('ai.handoff.template', [], 'en'));
    expect(DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('action', 'deflect')->count())->toBe(2);
    $handoff = DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('action', 'handoff')->first();
    expect(json_decode((string) $handoff->response_payload, true)['handoff_reason'])->toBe('blocked_topic_persistence');
});

it('FR-12/EC-02/EC-11/EC-12/FS-01 (AC-09 customer side): message_limit, unsupported_language, provider_refusal, quota_exhausted and DEGRADED all end in a handoff within the turn — zero unanswered customers', function (): void {
    // message_limit (guardrail default 8)
    [$tenantId, $customerId, $conversation] = turnTenant();
    for ($i = 0; $i < 8; $i++) {
        DB::table('messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'direction' => 'out', 'author_type' => 'ai', 'kind' => 'text', 'body' => 'earlier ai reply '.$i, 'delivery_state' => 'sent']);
    }
    turnDriver([], new ProviderResponse('One. Two. Three.', 10, 10));
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] still not solved');
    $reason = fn (object $c): string => json_decode((string) DB::table('ai_decisions')->where('conversation_id', $c->identifier())->where('action', 'handoff')->value('response_payload'), true)['handoff_reason'];
    expect($reason($conversation))->toBe('message_limit');

    // unsupported_language
    [$tenantId, $customerId, $conversation] = turnTenant();
    turnDriver([], new ProviderResponse('One. Two. Three.', 10, 10));
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] bonjour, où est mon dépôt?', 'fr');
    expect($reason($conversation))->toBe('unsupported_language')
        ->and(outboundBodies($conversation)[0])->toBe(__('ai.unsupported_language.template', [], 'en'));

    // provider_refusal (EC-11): raw refusal never reaches the customer
    [$tenantId, $customerId, $conversation] = turnTenant();
    turnDriver([], new ProviderResponse('', 0, 0, [], [unitVector(5)]), new ProviderResponse("I'm sorry, but I can't help with that request.", 20, 10), new ProviderResponse('One. Two. Three.', 10, 10));
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] how do I change my password?');
    expect($reason($conversation))->toBe('provider_refusal')
        ->and(implode(' ', outboundBodies($conversation)))->not->toContain("can't help");

    // degraded (FS-01): the provider is dead → handoff within the turn, template sent
    [$tenantId, $customerId, $conversation] = turnTenant();
    $driver = turnDriver([]); // classify answered; the turn's calls all throw
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] where is my withdrawal?');
    expect($reason($conversation))->toBe('degraded')
        ->and(outboundBodies($conversation))->toBe([__('ai.handoff.template', [], 'en')]);

    // quota_exhausted (EC-12)
    [$tenantId, $customerId, $conversation] = turnTenant();
    app(App\Support\Settings\SettingsRepository::class)->set(App\Support\Quota\Quota::AiTokensPerDay->settingKey(), 1000, tenantSurface: false);
    app(App\Support\Quota\QuotaMeter::class)->allow(App\Support\Quota\Quota::AiTokensPerDay, 990, App\Support\Quota\QuotaSurface::Staff);
    $driver = turnDriver([], new ProviderResponse('One. Two. Three.', 10, 10));
    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] hello again');
    // The gate refused the CLASSIFY call itself — and the customer still went
    // straight to a human within the turn (the stranded-customer gap the first
    // draft of this test exposed and the classify stage now closes).
    expect($reason($conversation))->toBe('quota_exhausted')
        ->and(outboundBodies($conversation))->toBe([__('ai.handoff.template', [], 'en')])
        ->and($driver->seen)->toBe([], 'a provider call was billed with the quota exhausted');
});

it('FR-07 handoff_path: with the chatbot DISABLED (default OFF) an inbound goes straight to a human — packet + template, no model turn', function (): void {
    [$tenantId, $customerId, $conversation] = turnTenant(chatbotEnabled: false);
    $driver = turnDriver([], new ProviderResponse('One. Two. Three.', 10, 10));

    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] where is my withdrawal?');

    expect(DB::table('tickets')->where('conversation_id', $conversation->identifier())->where('source', 'ai_handoff')->count())->toBe(1)
        ->and(outboundBodies($conversation))->toBe([__('ai.handoff.template', [], 'en')])
        ->and(DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('task', 'chatbot_turn')->whereNotNull('request_payload')->count())->toBe(0, 'a model turn ran while the chatbot was disabled');
});

it('AC-04 MID-FORM PREEMPTION (the step-9 re-proof): an active form + rg_risk ≥ rg_min → the RG path, the form is NOT continued', function (): void {
    [$tenantId, $customerId, $conversation] = turnTenant();
    app()->bind(FlowStateProvider::class, fn () => new class implements FlowStateProvider
    {
        public function activeState(string $conversationId): ?array
        {
            return ['form_id' => 'deposit_issue', 'step' => 2];
        }

        public function availableForms(?string $endpointRef = null): array
        {
            return ['deposit_issue'];
        }
    });
    turnDriver(['intent' => 'rg_risk', 'rg_risk' => 0.95, 'sentiment' => -2, 'urgency' => 3], new ProviderResponse((string) json_encode(['rg_risk' => 0.95, 'evidence' => 'chasing']), 100, 20), modelAction('continue_flow', ['state' => 'form:deposit_issue:3']));

    turnInbound($tenantId, $conversation, $customerId, '[FIXTURE] forget the form, I lost my rent money and I cannot stop betting');

    expect(outboundBodies($conversation))->toBe([__('ai.rg.template', [], 'en')])
        ->and(DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('action', 'continue_flow')->count())->toBe(0, 'the form continued past an RG override')
        ->and(DB::table('rg_flag_history')->where('customer_id', $customerId)->count())->toBe(1);
});

it('EC-01 (AC-01\'s pipeline clause): a six-message burst yields ONE coherent turn addressing the whole burst — one model call, one outbound', function (): void {
    [$tenantId, $customerId, $conversation] = turnTenant();
    $driver = turnDriver([], new ProviderResponse('', 0, 0, [], [unitVector(6)]), modelAction('handoff', ['reason' => 'customer_request']), new ProviderResponse('One. Two. Three.', 10, 10));

    // Six rapid arrivals; only the confirmed TAIL classifies (Doc 04's hint).
    $hint = app(App\Domain\Conversations\Inbound\BatchTailHint::class);
    $ids = [];
    foreach (['[FIXTURE] hi', '[FIXTURE] my deposit', '[FIXTURE] from yesterday', '[FIXTURE] 2000 ETB', '[FIXTURE] still missing', '[FIXTURE] can someone help me'] as $text) {
        $id = (string) Str::uuid7();
        DB::table('messages')->insert(['id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customerId, 'kind' => 'text', 'body' => $text, 'language' => 'en']);
        $hint->recordArrival($conversation->identifier(), $id);
        $ids[] = $id;
    }
    expect($hint->confirm($conversation->identifier(), end($ids)))->toBeTrue();

    foreach ($ids as $id) {
        $eventId = (string) Str::uuid7();
        DB::table('events')->insert(['id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'message.received', 'payload' => json_encode(['tenant_id' => $tenantId, 'message_id' => $id, 'conversation_id' => $conversation->identifier(), 'customer_id' => $customerId, 'kind' => 'text'])]);
        (new ClassifyInboundMessage($eventId))->handle(app(InboundClassification::class));
    }

    $classifications = DB::table('ai_decisions')->where('conversation_id', $conversation->identifier())->where('task', 'classify_message')->get();
    expect($classifications)->toHaveCount(1, 'the burst classified more than once');
    $burst = json_decode((string) $classifications->first()->request_payload, true)['burst'];
    expect($burst)->toContain('my deposit')->toContain('can someone help me');
    expect(outboundBodies($conversation))->toHaveCount(1, 'the burst produced more than one turn');
});
