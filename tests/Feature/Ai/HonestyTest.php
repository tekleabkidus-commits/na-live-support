<?php

declare(strict_types=1);

use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\Honesty\Disclosure;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 09 FR-19 / BR-05 / AC-05 (backend half; the DOM assertions ride the
 * step-12 browser run): the disclosure is PRESENT in the first greeting
 * across personas and languages — wording configurable, presence not; no
 * setting, config kind or surface can switch it off (N-08's absence); AI
 * messages carry author_type=ai (FR-19c) and are never edited (FR-19d,
 * Doc 04 FR-23 consumed). Sabotage #10 removes the disclosure from ONE
 * persona greeting in ONE language → the gate test and the first-greeting
 * proof go red.
 */
it('AC-05 (sabotage #10 target): a persona publishes ONLY if every declared greeting carries its language\'s disclosure; the offending language is named; a persona without greetings falls back to the platform greeting which discloses by construction', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $configs = app(ConfigVersions::class);
    $disclosure = app(Disclosure::class);

    $ok = $configs->draft('persona', 'default', ['name' => 'Fixture Bet Support', 'greeting' => [
        'en' => 'Welcome to Fixture Bet! '.$disclosure->wording('en').', here to help.',
        'am' => 'እንኳን ደህና መጡ! '.$disclosure->wording('am').'።',
    ]], $actor->identifier());
    $configs->publish($ok, $actor->identifier());
    expect($disclosure->greeting('en'))->toStartWith('Welcome to Fixture Bet!')->and($disclosure->greeting('am'))->toContain($disclosure->wording('am'));

    // ONE persona, ONE language without the disclosure → refused, naming the language.
    $bad = $configs->draft('persona', 'default', ['name' => 'Fixture Bet Support', 'greeting' => [
        'en' => 'Welcome to Fixture Bet! '.$disclosure->wording('en').'.',
        'am' => 'እንኳን ደህና መጡ! እንዴት ልርዳዎ?',
    ]], $actor->identifier());
    expect(fn () => $configs->publish($bad, $actor->identifier()))->toThrow(InvalidArgumentException::class, 'the am greeting does not contain the AI disclosure');
    expect(DB::table('ai_config_versions')->where('id', $bad)->value('status'))->toBe('draft', 'the refused version must not have published');
    expect($disclosure->greeting('am'))->toContain($disclosure->wording('am')); // the published persona is still the disclosing one

    // No greetings → the platform greeting, disclosing in both languages.
    $bare = $configs->draft('persona', 'default', ['name' => 'Bare'], $actor->identifier());
    $configs->publish($bare, $actor->identifier());
    foreach (['en', 'am'] as $lang) {
        expect($disclosure->greeting($lang))->toContain($disclosure->wording($lang));
    }
});

it('FR-19a wording vs presence: guardrails may REWORD the disclosure per language (white-label), a blank override is ignored — never a removal; and every first AI message in a conversation starts with the greeting, later ones do not', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $configs = app(ConfigVersions::class);
    $disclosure = app(Disclosure::class);

    $g = $configs->draft('guardrails', 'default', ['disclosure' => ['en' => 'You are chatting with Fixture Bet\'s virtual assistant (an AI)', 'am' => '   ']], $actor->identifier());
    $configs->publish($g, $actor->identifier());
    expect($disclosure->wording('en'))->toBe('You are chatting with Fixture Bet\'s virtual assistant (an AI)')
        ->and($disclosure->wording('am'))->toBe(__('ai.disclosure.sentence', [], 'am'), 'a blank override is not a removal');

    // First greeting through the REAL turn: answer #1 prefixed, answer #2 not.
    bindTenant($tenant);
    registerFakeChannel();
    $v = $configs->draft('thresholds', 'default', ['chatbot_enabled' => true, 'rg_min' => 0.8, 'handoff_below' => 0.3, 'auto_resolve_min' => 0.85, 'spam_min' => 0.9], $actor->identifier());
    $configs->publish($v, $actor->identifier());
    $customerId = (string) Str::uuid7();
    DB::table('customers')->insert(['id' => $customerId, 'tenant_id' => $tenantId, 'created_source' => 'telegram', 'language' => 'en']);
    $c = anotherConversation($tenantId, 'greet', $customerId);
    // A grounded answer both times (AC-02: an answer needs a citation) — one KB chunk, cited.
    app(App\Domain\Ai\Retrieval\KbIndexer::class)->index('018f6b2e-0000-7000-8000-0000000000c1', 0, 'en', 'Support hours: every day, all day.', fixtureVector: copilotVector(1));
    $classification = new ProviderResponse((string) json_encode(['intent' => 'other', 'sentiment' => 0, 'urgency' => 0, 'spam' => 0.0, 'rg_risk' => 0.0, 'confidence' => 0.9]), 10, 10);
    $answer = fn (string $text) => new ProviderResponse((string) json_encode(['action' => 'answer', 'text' => $text, 'citations' => ['018f6b2e-0000-7000-8000-0000000000c1']]), 10, 10);
    copilotDriver($classification, copilotEmbedding(1), $answer('[FIXTURE] Hello there!'), $classification, copilotEmbedding(1), $answer('[FIXTURE] Sure, go ahead.'));

    foreach (['[FIXTURE] hi', '[FIXTURE] can I ask something?'] as $text) {
        $id = (string) Str::uuid7();
        DB::table('messages')->insert(['id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $c->identifier(), 'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customerId, 'kind' => 'text', 'body' => $text, 'language' => 'en']);
        $eventId = (string) Str::uuid7();
        DB::table('events')->insert(['id' => $eventId, 'tenant_id' => $tenantId, 'name' => 'message.received', 'payload' => json_encode(['tenant_id' => $tenantId, 'message_id' => $id, 'conversation_id' => $c->identifier(), 'customer_id' => $customerId, 'kind' => 'text'])]);
        (new App\Jobs\Ai\ClassifyInboundMessage($eventId))->handle(app(App\Domain\Ai\Pipeline\InboundClassification::class));
    }
    $out = DB::table('messages')->where('conversation_id', $c->identifier())->where('direction', 'out')->orderBy('created_at')->get(['body', 'author_type']);
    expect($out)->toHaveCount(2)
        ->and($out[0]->body)->toStartWith($disclosure->greeting('en'))->toContain('virtual assistant (an AI)')->toContain('[FIXTURE] Hello there!')
        ->and($out[1]->body)->toStartWith('[FIXTURE] Sure, go ahead.')
        ->and($out[0]->author_type)->toBe('ai')->and($out[1]->author_type)->toBe('ai'); // FR-19c
});

it('N-08 / BR-05 ABSENCE: no registered setting, no config kind, no guardrail key can disable the disclosure PRESENCE — only wording is reachable; the immutable layer states the rule and its hash rides every decision', function (): void {
    $keys = app(SettingsRegistry::class)->keys();
    foreach ($keys as $key) {
        expect(preg_match('/disclos|honest/i', $key))->toBe(0, "a setting key touches the disclosure: {$key}");
    }
    $kinds = [];
    preg_match('/CHECK \(kind IN\s*\(([^)]*)\)/s', (string) file_get_contents(base_path('database/migrations/2026_08_20_000300_create_ai_config_versions_table.php')), $m);
    expect($m[1] ?? '')->not->toContain('disclos')->not->toContain('honest');
    expect(App\Domain\Ai\Prompt\ImmutableLayer::TEXT)->toContain('always disclose that you are an AI assistant in your first greeting and whenever asked');
    // The gate is not an opt-in: Disclosure has no parameter, flag or constructor switch that skips it.
    $reflection = new ReflectionMethod(Disclosure::class, 'gatePersona');
    expect($reflection->getNumberOfParameters())->toBe(1);
    expect(count(array_filter(get_class_methods(Disclosure::class), fn (string $m): bool => preg_match('/disable|skip|bypass|off/i', $m) === 1)))->toBe(0);
});

it('FR-19d: an AI-authored message is never edited post-send — Doc 04 FR-23\'s refusal is absolute (consumed, re-asserted from Doc 09\'s side)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    grantPermissions($actor, ['tickets.view', 'conversations.view', 'conversations.edit_message', 'conversations.delete_message'], 'S09 Editor');
    $id = (string) Str::uuid7();
    DB::table('messages')->insert(['id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'direction' => 'out', 'author_type' => 'ai', 'kind' => 'text', 'body' => 'AI said this.', 'delivery_state' => 'sent']);
    $message = App\Models\Message::query()->findOrFail($id);
    $refused = false;
    try {
        app(App\Domain\Conversations\Revisions\MessageEditing::class)->staffEdit($message, $actor, 'AI said THAT.');
    } catch (Throwable $e) {
        $refused = str_contains($e->getMessage(), 'never edited');
    }
    expect($refused)->toBeTrue('Doc 04 FR-23 did not refuse the AI edit');
    expect(DB::table('messages')->where('id', $id)->value('body'))->toBe('AI said this.');
});
