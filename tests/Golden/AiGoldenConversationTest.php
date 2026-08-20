<?php

declare(strict_types=1);

use App\Domain\Ai\Chatbot\ActionSchema;
use App\Domain\Ai\Chatbot\DiscardedAction;
use App\Domain\Ai\Chatbot\ValidatedAction;
use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Prompt\LayeredAssembly;
use Illuminate\Support\Facades\Cache;

// Breaker state is PLATFORM-scope; one live case's transient failure must
// not fail its neighbors through an open breaker (nightly triage clarity).
beforeEach(function (): void {
    Cache::flush();
});

/**
 * T-09/AC-01: THE GOLDEN-CONVERSATION SUITE — fixture dialogs through the
 * REAL assembly → orchestrator (replay driver) → closed-schema validation,
 * at PINNED config versions; §21's head order (the harness before any
 * surface). en + am. Every subsequent prompt change answers to this suite.
 *
 * The suite runs at TASK level here (the harness); the pipeline-level
 * clauses (batch-tail EC-01, post-resolution EC-08) join it as the pipeline
 * lands (steps 6/9) — the same suite grows, the fixtures stay.
 *
 * LIVE MODE (the nightly staging leg): AI_GOLDEN_LIVE=1 routes to the real
 * provider instead of replay — fixtures are [FIXTURE]-tagged, so the egress
 * guard admits them in staging by mechanism.
 */
function goldenRouting(): void
{
    $provider = env('AI_GOLDEN_LIVE') === '1' ? 'addis' : 'replay';
    $model = $provider === 'addis' ? 'addis-አሌፍ-፩' : 'replay-model';
    config(['ai.default_routing.strong' => [['provider' => $provider, 'model' => $model]]]);
}

function pinnedPersona(): void
{
    // The PINNED config the suite runs at: version 1, published — asserting
    // the version in every case keeps "pinned" honest.
    $configs = app(ConfigVersions::class);
    $actor = (string) Illuminate\Support\Str::uuid7();
    $v = $configs->draft('persona', 'default', [
        'name' => 'Fixture Bet Support',
        'tone' => 'warm, brief, honest',
    ], $actor);
    $configs->publish($v, $actor);
}

/**
 * The STEP-0.2-proven strict template (0/23 malformed at the capability
 * check) — the live nightly leg needs the same discipline the replay
 * recordings embody.
 */
function goldenTaskTemplate(array $availableForms = []): string
{
    $forms = $availableForms === [] ? 'none' : implode(', ', $availableForms);

    return <<<'TPL'
        Decide the next chatbot action. Return ONLY a JSON object, no prose, no markdown fences. It must be EXACTLY ONE of:
        {"action":"answer","text":"...","citations":["<article_ref>"]}
        {"action":"run_form","form_id":"..."}
        {"action":"continue_flow","state":"..."}
        {"action":"handoff","reason":"<one of: low_confidence|customer_request|blocked_intent|form_route|no_grounding|message_limit|post_resolution_loop|invalid_action|blocked_topic_persistence|unsupported_language|provider_refusal|rg_risk|quota_exhausted|degraded>"}
        {"action":"resolve","closing_text":"..."}
        Citations may name ONLY article_refs present in the retrieved-knowledge block. With no citation available for a money/policy answer you must handoff with reason "no_grounding".
        TPL."\nAvailable forms (run_form may name ONLY these): {$forms}. JSON only — no prose.";
}

/** @return list<array{file: string, fixture: array<string, mixed>}> */
function goldenFixtures(): array
{
    $cases = [];
    foreach (glob(base_path('fixtures/ai/golden/*.json')) ?: [] as $file) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $cases[] = ['file' => basename($file), 'fixture' => $decoded];
        }
    }

    return $cases;
}

it('AC-01: every golden dialog yields its expected action type (or its expected refusal) at the pinned config, en + am', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    goldenRouting();
    pinnedPersona();

    $cases = goldenFixtures();
    expect(count($cases))->toBeGreaterThanOrEqual(9, 'the golden corpus shrank — the suite would pin less than it claims');

    $assembly = app(LayeredAssembly::class);
    $orchestrator = app(AiOrchestrator::class);
    $schema = new ActionSchema;
    $languagesSeen = [];

    foreach ($cases as $case) {
        $fixture = $case['fixture'];
        $language = is_string($fixture['language'] ?? null) ? $fixture['language'] : 'en';
        $languagesSeen[$language] = true;

        $context = [
            'language' => $language,
            'prior_topics' => [],
            'acquisition' => null,
            'window' => is_array($fixture['dialog'] ?? null) ? $fixture['dialog'] : [],
        ];
        $retrieved = is_array($fixture['retrieved'] ?? null) ? $fixture['retrieved'] : [];

        $forms = [];
        if (is_array($fixture['available_forms'] ?? null)) {
            foreach ($fixture['available_forms'] as $form) {
                if (is_string($form)) {
                    $forms[] = $form;
                }
            }
        }
        $assembled = $assembly->assemble('chatbot_turn', goldenTaskTemplate($forms), $context, $retrieved);
        $result = $orchestrator->execute('chatbot_turn', $assembled['messages']);
        $allowedRefs = [];
        foreach ($retrieved as $chunk) {
            if (is_array($chunk) && is_string($chunk['article_ref'] ?? null)) {
                $allowedRefs[] = $chunk['article_ref'];
            }
        }
        $validated = $schema->validate(json_decode((string) $result['response']->content, true), $allowedRefs);

        $expected = is_array($fixture['expect'] ?? null) ? $fixture['expect'] : [];

        if (($expected['discarded'] ?? false) === true) {
            expect($validated)->toBeInstanceOf(DiscardedAction::class, $case['file'].': the schema net let the case through')
                ->and($validated instanceof DiscardedAction ? $validated->detail : '')->toContain(
                    is_string($expected['detail_contains'] ?? null) ? $expected['detail_contains'] : '',
                );

            continue;
        }

        expect($validated)->toBeInstanceOf(ValidatedAction::class, sprintf(
            '%s: expected a valid action, got a discard (%s: %s)',
            $case['file'],
            $validated instanceof DiscardedAction ? $validated->category : '?',
            $validated instanceof DiscardedAction ? $validated->detail : '?',
        ));
        assert($validated instanceof ValidatedAction);
        expect($validated->action)->toBe($expected['action'], $case['file']);

        if (isset($expected['reason'])) {
            expect($validated->payload['reason'])->toBe($expected['reason'], $case['file']);
        }
        if (isset($expected['form_id'])) {
            expect($validated->payload['form_id'])->toBe($expected['form_id'], $case['file']);
        }
        if (isset($expected['citations_min'])) {
            $citations = $validated->payload['citations'] ?? [];
            expect(is_array($citations) ? count($citations) : 0)
                ->toBeGreaterThanOrEqual((int) $expected['citations_min'], $case['file']);
        }
    }

    // en + am both exercised (§14's golden-suite clause), and the pinned
    // config is version 1 published — "pinned" stays honest.
    expect($languagesSeen)->toHaveKeys(['en', 'am']);
    $active = app(ConfigVersions::class)->published('persona', 'default');
    expect($active?->version)->toBe(1);
});

it('AC-05 (the trap clause, task level): the transaction-fact trap NEVER yields a confirmable answer — en and am both', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    goldenRouting();
    pinnedPersona();

    $schema = new ActionSchema;
    $assembly = app(LayeredAssembly::class);
    $orchestrator = app(AiOrchestrator::class);

    foreach (goldenFixtures() as $case) {
        if (! str_starts_with($case['file'], 'trap-')) {
            continue;
        }
        $fixture = $case['fixture'];
        $assembled = $assembly->assemble('chatbot_turn', goldenTaskTemplate(), [
            'language' => is_string($fixture['language'] ?? null) ? $fixture['language'] : 'en',
            'prior_topics' => [],
            'acquisition' => null,
            'window' => is_array($fixture['dialog'] ?? null) ? $fixture['dialog'] : [],
        ]);
        $result = $orchestrator->execute('chatbot_turn', $assembled['messages']);
        // No retrieval ran for the traps: the allowed set is EMPTY — an
        // invented citation cannot launder the answer (the live finding).
        $validated = $schema->validate(json_decode((string) $result['response']->content, true), []);

        // The recorded output DOES try to confirm ("Yes, your deposit was
        // received") — the schema net catches it: uncited answer, discarded,
        // and the step-9 turn maps this to no_grounding handoff (BR-02).
        expect($validated)->toBeInstanceOf(DiscardedAction::class, $case['file'].': the trap produced a sendable answer');
    }
});
