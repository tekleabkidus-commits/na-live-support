<?php

declare(strict_types=1);

use App\Domain\Ai\DecisionLog\DecisionWriter;
use App\Domain\Ai\DecisionLog\OutcomeEnrichment;
use App\Domain\Ai\Prompt\ImmutableLayer;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-20/T-08/NFR-05: the decision log's writer, the sampled FR-21 event
 * (amendment flag 1), and the enrichment pen — the product's memory,
 * proven before any surface exists (§21's head order).
 */
it('FR-20: record + flush lands the row with layer_hashes carrying the immutable hash (T-02 asserted in decisions)', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);

    $writer = app(DecisionWriter::class);
    $record = decisionFixture([
        'intent' => 'deposits', 'confidence' => 0.91, 'action' => 'answer',
        'tokensIn' => 400, 'tokensOut' => 60, 'latencyMs' => 2100,
        'requestPayload' => ['messages' => [['role' => 'user', 'content' => '[FIXTURE] hi']]],
        'responsePayload' => ['content' => '{"action":"answer"}'],
    ]);
    $writer->record($record);
    $writer->flush(); // sync queue: the batched insert runs inline

    $row = DB::table('ai_decisions')->where('id', $record->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->task)->toBe('classify_message')
        ->and($row->tokens_in)->toBe(400)
        ->and(json_decode((string) $row->layer_hashes, true)['immutable'])->toBe(ImmutableLayer::hash())
        ->and($row->request_payload)->not->toBeNull()
        ->and($row->created_at)->not->toBeNull();
});

it('FR-21 (flag 1): at 1000 permille every decision emits the SAMPLED event with the closed Q7 payload; at 0 none do', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $settings = app(SettingsRepository::class);

    $settings->set('ai.decisions.event_sample_permille', 1000, tenantSurface: false);
    $writer = app(DecisionWriter::class);
    $sampled = decisionFixture(['action' => 'handoff', 'errorCategory' => 'invalid_action']);
    $writer->record($sampled);
    $writer->flush();

    $event = DB::table('outbox')->where('name', 'ai.decision.logged')->orderByDesc('occurred_at')->first();
    expect($event)->not->toBeNull();
    $payload = json_decode((string) $event->payload, true);
    expect($payload['decision_id'])->toBe($sampled->id)
        ->and($payload['action'])->toBe('handoff')
        ->and($payload['error_category'])->toBe('invalid_action')
        ->and(collect($payload)->keys()->sort()->values()->all())->toBe(
            ['action', 'decision_id', 'error_category', 'surface', 'task', 'tenant_id'],
            'the Q7 payload drifted from the closed catalog shape',
        );

    $settings->set('ai.decisions.event_sample_permille', 0, tenantSurface: false);
    $before = DB::table('outbox')->where('name', 'ai.decision.logged')->count();
    $writer->record(decisionFixture());
    $writer->flush();
    expect(DB::table('outbox')->where('name', 'ai.decision.logged')->count())->toBe($before, 'permille 0 still emitted');
});

it('T-08: the enrichment pen writes outcome + meta from the CLOSED category list; an invented category throws', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);

    $writer = app(DecisionWriter::class);
    $record = decisionFixture();
    $writer->record($record);
    $writer->flush();

    $enrichment = app(OutcomeEnrichment::class);
    expect($enrichment->enrich($record->id, 'resolved_then_reopened', ['ticket_id' => (string) Str::uuid7()]))->toBeTrue();

    $row = DB::table('ai_decisions')->where('id', $record->id)->first();
    expect($row->outcome)->toBe('resolved_then_reopened')
        ->and($row->request_payload)->toBeNull() // this fixture carried none — and enrichment could not have touched it anyway (grant physics)
        ->and(fn () => $enrichment->enrich($record->id, 'model_was_great'))->toThrow(InvalidArgumentException::class);
});

it('NFR-05 (dev-hardware-indicative): record() stages within the 3 ms budget', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $writer = app(DecisionWriter::class);

    $stats = measure(function () use ($writer): void {
        $writer->record(decisionFixture());
    });

    reportBudget('NFR-05 decision record() staging', 3.0, $stats);
    expect($stats['p99'])->toBeLessThan(assertedBudget(3.0));
});
