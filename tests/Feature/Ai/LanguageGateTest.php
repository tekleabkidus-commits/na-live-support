<?php

declare(strict_types=1);

use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\Language\LanguageGate;
use App\Domain\Ai\Simulation\SimulationEngine;
use App\Domain\Ai\Simulation\ThresholdPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Doc 09 §14's hard rule AS CODE (S09 step 13): an endpoint cannot enable a
 * language missing its RG template — inside BR-07's one thresholds publish
 * door; the launch pair passes, a third language without templates is
 * refused naming the language + the missing template, and becomes
 * enableable once a guardrails override supplies every template; the
 * chatbot answers only in an endpoint's enabled languages.
 */
function thresholdsDraftFor(array $payload, string $actor): array
{
    $configs = app(ConfigVersions::class);
    $id = $configs->draft('thresholds', 'default', $payload, $actor);
    $day = DB::selectOne("SELECT (date_trunc('day', clock_timestamp()) - interval '1 day')::text AS d")->d;
    $token = SimulationEngine::tokenFor($day, array_intersect_key($payload, array_flip(['rg_min', 'spam_min', 'handoff_below', 'auto_resolve_min'])), null);

    return [$id, $day, $token];
}

it('§14: the launch pair publishes; a third language with NO templates is REFUSED at the BR-07 door naming it and the missing template; the record stays a draft', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $values = ['chatbot_enabled' => true, 'rg_min' => 0.8, 'spam_min' => 0.9, 'handoff_below' => 0.3, 'auto_resolve_min' => 0.85];
    $publisher = app(ThresholdPublisher::class);

    [$ok, $day, $token] = thresholdsDraftFor($values + ['languages' => ['en', 'am']], $actor->identifier());
    $publisher->publish($ok, $token, $day, $actor->identifier());
    expect(app(LanguageGate::class)->enabledFor(null))->toBe(['en', 'am']);

    [$bad, $day, $token] = thresholdsDraftFor($values + ['languages' => ['en', 'am', 'om']], $actor->identifier());
    expect(fn () => $publisher->publish($bad, $token, $day, $actor->identifier()))->toThrow(InvalidArgumentException::class, 'language "om" cannot be enabled — its RG template is missing');
    expect(DB::table('ai_config_versions')->where('id', $bad)->value('status'))->toBe('draft')
        ->and(app(LanguageGate::class)->enabledFor(null))->toBe(['en', 'am']);
    expect(app(LanguageGate::class)->missingTemplates('om'))->toBe(['RG', 'handoff', 'disclosure']);
});

it('§14: a language becomes enableable ONLY once a guardrails override supplies its RG template, handoff template and disclosure — partial sets still refuse', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $configs = app(ConfigVersions::class);
    $gate = app(LanguageGate::class);

    $g1 = $configs->draft('guardrails', 'default', ['rg_template' => ['om' => 'Galatoomaa — gargaarsi dhugaa ni jira.'], 'disclosure' => ['om' => 'Ani gargaaraa AI dha']], $actor->identifier());
    $configs->publish($g1, $actor->identifier());
    expect($gate->missingTemplates('om'))->toBe(['handoff'], 'the handoff template is a platform seed this language lacks');
    expect(fn () => $gate->assertEnableable(['om']))->toThrow(InvalidArgumentException::class, 'handoff template is missing');
    expect(fn () => $gate->assertEnableable(['en', 'am']))->not->toThrow(InvalidArgumentException::class);
});

it('§14 in the turn: a customer writing in a language the endpoint does not enable gets the capability template + unsupported_language — even when it is a launch language the record switched off', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $values = ['chatbot_enabled' => true, 'rg_min' => 0.8, 'spam_min' => 0.9, 'handoff_below' => 0.3, 'auto_resolve_min' => 0.85, 'languages' => ['en']];
    [$id, $day, $token] = thresholdsDraftFor($values, $actor->identifier());
    app(ThresholdPublisher::class)->publish($id, $token, $day, $actor->identifier());

    registerFakeChannel();
    $customerId = (string) Illuminate\Support\Str::uuid7();
    DB::table('customers')->insert(['id' => $customerId, 'tenant_id' => $tenantId, 'created_source' => 'telegram', 'language' => 'am']);
    $c = anotherConversation($tenantId, 'lang', $customerId);
    copilotDriver(new App\Domain\Ai\Providers\ProviderResponse((string) json_encode(['intent' => 'other', 'sentiment' => 0, 'urgency' => 0, 'spam' => 0.0, 'rg_risk' => 0.0, 'confidence' => 0.9]), 10, 10), new App\Domain\Ai\Providers\ProviderResponse('[FIXTURE] summary.', 10, 10));
    $id = (string) Illuminate\Support\Str::uuid7();
    DB::table('messages')->insert(['id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $c->identifier(), 'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customerId, 'kind' => 'text', 'body' => '[FIXTURE] ሰላም', 'language' => 'am']);
    $eventId = (string) Illuminate\Support\Str::uuid7();
    DB::table('events')->insert(['id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'message.received', 'payload' => json_encode(['tenant_id' => $tenantId, 'message_id' => $id, 'conversation_id' => $c->identifier(), 'customer_id' => $customerId, 'kind' => 'text'])]);
    (new App\Jobs\Ai\ClassifyInboundMessage($eventId))->handle(app(App\Domain\Ai\Pipeline\InboundClassification::class));

    $out = DB::table('messages')->where('conversation_id', $c->identifier())->where('direction', 'out')->orderBy('created_at')->pluck('body')->all();
    expect($out[0])->toEndWith(__('ai.unsupported_language.template', [], 'en'));
    expect(DB::table('ai_decisions')->where('conversation_id', $c->identifier())->where('action', 'handoff')->value('response_payload'))->toContain('unsupported_language');
});
