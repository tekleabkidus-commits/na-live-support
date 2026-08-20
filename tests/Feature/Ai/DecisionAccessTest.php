<?php

declare(strict_types=1);

use App\Domain\Ai\DecisionLog\DecisionWriter;
use Illuminate\Support\Facades\DB;

/**
 * SEC-06's access split, proven at the door: ai.decisions.view reads the
 * CURATED list (the payload pair structurally absent — no tenant door to
 * raw payloads exists at all); no permission, no door; C-05 cursor walks.
 */
function seedDecisions(int $count): void
{
    $writer = app(DecisionWriter::class);
    for ($i = 0; $i < $count; $i++) {
        $writer->record(decisionFixture([
            'requestPayload' => ['messages' => [['role' => 'user', 'content' => '[FIXTURE] secret-adjacent prompt text '.$i]]],
            'responsePayload' => ['content' => 'model output '.$i],
        ]));
    }
    $writer->flush();
}

it('SEC-06: the door serves the curated allowlist — the payload pair NEVER rides, whatever the row holds', function (): void {
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    seedDecisions(3);

    $viewer = ticketActor(['ai.decisions.view']);
    $response = test()->actingAs($viewer, 'staff')->getJson('https://localhost/api/staff/ai/decisions');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(3);
    foreach ($data as $row) {
        expect($row)->toHaveKeys(['id', 'surface', 'task', 'tokens_in', 'created_at'])
            ->and(array_key_exists('request_payload', $row))->toBeFalse('a raw request payload rode the tenant door (SEC-06)')
            ->and(array_key_exists('response_payload', $row))->toBeFalse('a raw response payload rode the tenant door (SEC-06)')
            ->and(json_encode($row))->not->toContain('secret-adjacent prompt text');
    }
});

it('SEC-06: without ai.decisions.view the door refuses', function (): void {
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    seedDecisions(1);

    $nobody = ticketActor(['tickets.view']);
    test()->actingAs($nobody, 'staff')->getJson('https://localhost/api/staff/ai/decisions')->assertForbidden();
});

it('C-05: the cursor walks the log without overlap or gap', function (): void {
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);
    seedDecisions(5);

    $viewer = ticketActor(['ai.decisions.view']);
    $first = test()->actingAs($viewer, 'staff')->getJson('https://localhost/api/staff/ai/decisions?limit=3');
    $first->assertOk();
    $cursor = $first->json('next_cursor');
    expect($first->json('data'))->toHaveCount(3)->and($cursor)->not->toBeNull();

    $second = test()->actingAs($viewer, 'staff')->getJson('https://localhost/api/staff/ai/decisions?limit=3&cursor='.urlencode((string) $cursor));
    $second->assertOk();
    $ids = array_merge(
        array_column($first->json('data'), 'id'),
        array_column($second->json('data'), 'id'),
    );
    expect($second->json('data'))->toHaveCount(2)
        ->and(count(array_unique($ids)))->toBe(5, 'the cursor overlapped or skipped');
});

it('the split\'s other half stands: the platform connection CAN read the payload columns app cannot', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    commitFixtures(); // the platform connection sees only committed rows

    try {
        bindTenant($tenantId, App\Support\Tenancy\ResolutionSource::Console);
        seedDecisions(1);
        commitFixtures();

        $payload = asPlatformWorker(fn (): mixed => DB::connection('pgsql_platform')
            ->table('ai_decisions')->where('tenant_id', $tenantId)->whereNotNull('request_payload')->value('request_payload'));

        expect($payload)->not->toBeNull('the platform tier lost its read — the debug path is dead');
    } finally {
        DB::purge('pgsql');
        cleanupTenants([$tenantId]);
    }
});
