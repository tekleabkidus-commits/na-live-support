<?php

declare(strict_types=1);

use App\Domain\Customers\Search\CustomerSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 08 FS-01 (S08 step 8) — Meilisearch down: directory search DEGRADES to
| a LABELED DB prefix search on names/phone through the SAME scope brain;
| never a 500, never a scope widening. (FR-20's table door and EC-08 join
| this file at the directory steps; FS-01's "panel unaffected" clause is
| proven when T-07's panel endpoint lands at step 9 — the panel reads the
| DB, not this index, by construction.)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** A staff-linked phone identity, directly at the storage layer (the FS-01 fixtures need the value, not the door). */
function directoryPhoneIdentity(string $tenantId, string $customerId, string $e164): void
{
    // The deprecated channel/external_user_id pair stays NOT NULL through the
    // C-12 contract window — filled the way LinkIdentity fills it.
    DB::table('customer_identities')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'customer_id' => $customerId,
        'channel' => 'phone', 'external_user_id' => $e164,
        'type' => 'phone', 'value' => $e164, 'source' => 'staff',
    ]);
}

it('FS-01: Meilisearch down → the dead-port drill — a LABELED degrade (degraded: true) answering DB prefix hits on name AND phone, still scope-constrained by the same brain', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $all = customersScopedActor('All', 'S08 Dir FS1 All');
    $own = customersScopedActor('Own', 'S08 Dir FS1 Own');

    $named = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $named)->update(['display_name' => 'Selamawit Dirfixture']);
    directoryPhoneIdentity($tenantId, $named, '+251900001234');

    // The sabotage-#11 idiom (S07 precedent): a dead port, not a mock.
    config()->set('services.meilisearch.host', 'http://127.0.0.1:59999');

    $search = app(CustomerSearch::class);

    // Name prefix, labeled.
    $byName = $search->search('Selamaw', $all);
    expect($byName['degraded'])->toBeTrue('the outage was not labeled — FS-01 requires the degrade to say so')
        ->and(array_column($byName['hits'], 'id'))->toBe([$named]);

    // Phone prefix (the document's own fallback list: names/phone).
    $byPhone = $search->search('+2519000012', $all);
    expect($byPhone['degraded'])->toBeTrue()
        ->and(array_column($byPhone['hits'], 'id'))->toBe([$named]);

    // The degrade rides the SAME CustomerScope constraint: an Own-scoped
    // viewer with no assigned ticket for this customer still sees NOTHING —
    // an outage never widens scope (the one-brain proof).
    $scoped = $search->search('Selamaw', $own);
    expect($scoped['degraded'])->toBeTrue()
        ->and($scoped['hits'])->toBe([], 'the degrade path leaked an out-of-scope customer — FS-01 forked a second scope engine');

    // The HTTP door: 200 with the label in-band, never a 500.
    $response = test()->actingAs($all, 'staff')->getJson('https://localhost/api/staff/customers/search?q=Selamaw');
    $response->assertOk();
    expect($response->json('degraded'))->toBeTrue()
        ->and(array_column($response->json('hits'), 'id'))->toBe([$named]);
});
