<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 08 BR-05 (S08 step 11) — tombstones REDIRECT FOREVER, never 404: old
| ticket links in emails and notes must keep working. Sabotage #13 teaches
| the page door to 404 a tombstone and this suite goes red.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

it('BR-05: a MERGED profile\'s page RESOLVES as the tombstone surface — survivor named, undo offered in-window to a customers.merge holder — and a live profile renders the profile page', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $viewer = ticketActor(['customers.view', 'customers.merge'], 'S08 Tombstone Viewer');

    $survivor = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $survivor)->update(['display_name' => 'Tombstone Survivor']);
    $merged = enforcedCustomer($tenantId, '2026-06-01 10:05:00+00');
    DB::table('customers')->where('id', $merged)->update(['merged_into_id' => $survivor]);
    $snapshotId = (string) Illuminate\Support\Str::uuid7();
    DB::statement(
        "INSERT INTO customer_merge_snapshots (id, tenant_id, survivor_id, merged_id, payload, expires_at)
         VALUES (?, ?, ?, ?, ?, clock_timestamp() + interval '30 days')",
        [$snapshotId, $tenantId, $survivor, $merged, json_encode(['moved' => ['conversations' => [], 'tickets' => [], 'notes' => [], 'identities' => []]])],
    );

    $page = test()->actingAs($viewer, 'staff')->get("https://localhost/customers/{$merged}");
    $page->assertOk();   // NEVER a 404 — the whole point of BR-05
    $page->assertInertia(fn ($assert) => $assert
        ->component('Customers/Tombstone', shouldExist: false)
        ->where('survivorId', $survivor)
        ->where('survivorName', 'Tombstone Survivor')
        ->where('snapshotId', $snapshotId)
        ->where('canUndo', true));

    // The live profile renders the profile page, not the tombstone.
    bindTenant($tenant);
    $live = test()->actingAs($viewer, 'staff')->get("https://localhost/customers/{$survivor}");
    $live->assertOk();
    $live->assertInertia(fn ($assert) => $assert->component('Customers/Profile', shouldExist: false));

    // A viewer WITHOUT customers.merge sees the tombstone but no undo.
    bindTenant($tenant);
    $reader = ticketActor(['customers.view'], 'S08 Tombstone Reader');
    test()->actingAs($reader, 'staff')->get("https://localhost/customers/{$merged}")
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert->component('Customers/Tombstone', shouldExist: false)->where('canUndo', false));

    // C-10 stays intact: a genuinely unknown id is a 404.
    bindTenant($tenant);
    test()->actingAs($viewer, 'staff')
        ->get('https://localhost/customers/'.Illuminate\Support\Str::uuid7())
        ->assertStatus(404);
});
