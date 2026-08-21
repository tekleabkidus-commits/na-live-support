<?php

declare(strict_types=1);

use App\Domain\Ai\DecisionLog\WeeklyOutputs;
use App\Domain\Ai\Providers\ProviderResponse;

/**
 * Doc 09 FR-20's weekly outputs as read models over the decision log: the
 * top-10 unanswerable (no_grounding handoffs + EC-09 locks, by intent, with
 * the message ids as the [D10] one-click-draft seam), the calibration curve
 * (confidence buckets vs outcomes), the RG precision queue (delegated to
 * the [D09] EC-06 read model).
 */
it('top unanswerable: no_grounding handoffs and locked regenerations group by intent, ranked by count, carrying message ids (the [D10] draft seam) and sample conversations — identifiers only', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $writer = app(App\Domain\Ai\DecisionLog\DecisionWriter::class);
    foreach ([['bonuses', 'no_grounding'], ['bonuses', 'no_grounding'], ['withdrawals', 'no_grounding'], ['bonuses', 'customer_request']] as [$intent, $reason]) {
        $writer->record(decisionFixture(['surface' => 'chatbot', 'task' => 'chatbot_turn', 'action' => 'handoff', 'intent' => $intent, 'conversationId' => $conversation->identifier(), 'messageId' => (string) Illuminate\Support\Str::uuid7(), 'responsePayload' => ['handoff_reason' => $reason]]));
    }
    $writer->flush();
    // An EC-09 lock on a bonuses question.
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] is the bonus valid on live bets?', 'en', ['intent' => 'bonuses']);
    app(App\Support\Settings\SettingsRepository::class)->set('ai.copilot.regenerate_cooldown_seconds', 0, tenantSurface: false);
    $draft = new ProviderResponse((string) json_encode(['text' => '[FIXTURE] Checking.', 'citations' => [], 'confidence' => 0.8]), 1, 1);
    copilotDriver(copilotEmbedding(1), $draft, copilotEmbedding(1), $draft, copilotEmbedding(1), $draft, copilotEmbedding(1));
    for ($i = 0; $i < 4; $i++) {
        app(App\Domain\Ai\Copilot\ComposerActions::class)->draft($actor->identifier(), $conversation);
    }

    $top = app(WeeklyOutputs::class)->topUnanswerable();

    expect($top[0]['intent'])->toBe('bonuses')->and($top[0]['count'])->toBe(3)->and($top[0]['no_grounding'])->toBe(2)->and($top[0]['locked'])->toBe(1)
        ->and($top[0]['message_ids'])->toHaveCount(3)
        ->and($top[1]['intent'])->toBe('withdrawals')->and($top[1]['count'])->toBe(1);
    array_walk_recursive($top, function ($leaf): void {
        expect(is_int($leaf) || (is_string($leaf) && (preg_match('/^[0-9a-f-]{36}$/', $leaf) === 1 || preg_match('/^[a-z_]+$/', $leaf) === 1)))->toBeTrue('text leaked into the weekly output: '.var_export($leaf, true));
    });
});

it('calibration curve: confidence buckets vs outcomes over chatbot + copilot rows — resolved, reopened, handoff, discarded counted per bucket', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $writer = app(App\Domain\Ai\DecisionLog\DecisionWriter::class);
    $rows = [
        ['confidence' => 0.95, 'action' => 'answer', 'outcome' => 'resolved'],
        ['confidence' => 0.92, 'action' => 'answer', 'outcome' => 'resolved_then_reopened'],
        ['confidence' => 0.35, 'action' => 'handoff', 'outcome' => null],
        ['confidence' => 0.31, 'action' => 'handoff', 'outcome' => null],
        ['confidence' => 0.60, 'action' => 'artifact', 'outcome' => 'discarded'],
    ];
    $ids = [];
    foreach ($rows as $r) {
        $ids[] = $id = (string) Illuminate\Support\Str::uuid7();
        $writer->record(decisionFixture(['id' => $id, 'surface' => $r['action'] === 'artifact' ? 'copilot' : 'chatbot', 'task' => $r['action'] === 'artifact' ? 'draft_reply' : 'chatbot_turn', 'action' => $r['action'], 'confidence' => $r['confidence']]));
    }
    $writer->flush();
    $enrich = app(App\Domain\Ai\DecisionLog\OutcomeEnrichment::class);
    foreach ($rows as $i => $r) {
        if ($r['outcome'] !== null) {
            $enrich->enrich($ids[$i], $r['outcome']);
        }
    }

    $curve = app(WeeklyOutputs::class)->calibrationCurve();
    $byBucket = array_column($curve, null, 'bucket');
    expect($byBucket['0.9-1.0'])->toMatchArray(['total' => 2, 'resolved' => 1, 'reopened' => 1, 'handoff' => 0, 'discarded' => 0])
        ->and($byBucket['0.3-0.4'])->toMatchArray(['total' => 2, 'handoff' => 2])
        ->and($byBucket['0.6-0.7'])->toMatchArray(['total' => 1, 'discarded' => 1]);
});

it('RG precision queue delegates to RgCalibration (the [D09] EC-06 read model) — same shape, one implementation', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    expect(app(WeeklyOutputs::class)->rgPrecisionQueue())->toBe(app(App\Domain\Ai\Rg\RgCalibration::class)->reviewQueue());
});
