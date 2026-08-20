<?php

declare(strict_types=1);

use App\Domain\Ai\Prompt\ImmutableLayer;
use App\Jobs\Ai\ReduceAiDecisionPayloads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SEC-06's PRIVACY BY SCHEDULE, executed: payloads older than 30 days
 * reduce to hash + stamp; younger rows untouched; a second run reduces
 * nothing (idempotent). The job is the ONLY pen (app holds no grant on the
 * reduction columns — AiGrantPhysicsTest's derivation).
 */
function platformDecision(string $tenantId, int $daysOld, bool $withPayloads = true): string
{
    $id = (string) Str::uuid7();

    asPlatformWorker(fn (): bool => DB::connection('pgsql_platform')->table('ai_decisions')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'surface' => 'chatbot',
        'task' => 'chatbot_turn',
        'provider' => 'fake',
        'model' => 'fixture-model',
        'layer_hashes' => json_encode(['immutable' => ImmutableLayer::hash()]),
        'request_payload' => $withPayloads ? json_encode(['messages' => [['role' => 'user', 'content' => '[FIXTURE] old prompt']]]) : null,
        'response_payload' => $withPayloads ? json_encode(['content' => 'old output']) : null,
        'created_at' => now()->subDays($daysOld)->toIso8601String(),
    ]));

    return $id;
}

it('SEC-06: a 31-day-old payload pair reduces to hash + stamp; a fresh row is untouched; the run is idempotent', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    commitFixtures(); // the platform inserts below need the committed tenant row
    bindTenant($tenantId, App\Support\Tenancy\ResolutionSource::Console);

    try {

        $old = platformDecision($tenantId, 31);
        $fresh = platformDecision($tenantId, 1);

        (new ReduceAiDecisionPayloads)->handle();

        $read = fn (string $id): object => asPlatformWorker(fn (): object => DB::connection('pgsql_platform')
            ->table('ai_decisions')->where('id', $id)->first());

        $oldRow = $read($old);
        expect($oldRow->request_payload)->toBeNull('the old request payload survived its schedule')
            ->and($oldRow->response_payload)->toBeNull()
            ->and($oldRow->payload_hash)->toMatch('/^[0-9a-f]{64}$/')
            ->and($oldRow->payloads_reduced_at)->not->toBeNull();

        $freshRow = $read($fresh);
        expect($freshRow->request_payload)->not->toBeNull('a fresh row was reduced early')
            ->and($freshRow->payloads_reduced_at)->toBeNull();

        $hashBefore = $oldRow->payload_hash;
        (new ReduceAiDecisionPayloads)->handle();
        expect($read($old)->payload_hash)->toBe($hashBefore, 'a second run re-reduced — not idempotent');
    } finally {
        DB::purge('pgsql');
        cleanupTenants([$tenantId]);
    }
});
