<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Customers\Identity\IdentityResolution;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 08 T-07 / FR-21-server-half (S08 step 9) — ONE aggregate endpoint:
| header + identities immediately, section STUBS, sections independent.
| C-10 on every door; the RG absence rides CustomerPayload (proven at step
| 4) and is re-asserted here THROUGH the panel path.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

it('T-07: the aggregate answers header + identities (provenance ALWAYS — FR-05, incl. username history) + section stubs; recent-tickets caps at 5, its own fetch', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $viewer = customersScopedActor('All', 'S08 Panel Viewer');

    // A resolver-minted identity with a rename — the provenance the panel
    // must render is the provenance the resolver actually wrote.
    $resolver = app(IdentityResolution::class);
    $customerId = $resolver->resolveInbound('telegram', new InboundSender('panel-900100', 'Panel Fixture', '@fixture_panel_old'))->customerId;
    $resolver->resolveInbound('telegram', new InboundSender('panel-900100', 'Panel Fixture', '@fixture_panel_new'));
    DB::table('customers')->where('id', $customerId)->update(['display_name' => 'Panel Fixture Staffname']);

    $conversation = anotherConversation($tenantId, 'panel-tix', $customerId);
    foreach (range(1, 7) as $i) {
        inboxTicketRow($tenantId, $conversation, $i === 1 ? 'open' : 'closed');
    }

    $panel = test()->actingAs($viewer, 'staff')->getJson("https://localhost/api/staff/customers/{$customerId}/panel");
    $panel->assertOk();

    expect($panel->json('header.display_name'))->toBe('Panel Fixture Staffname')
        ->and($panel->json('header.updated_at'))->toBeString() // EC-11's expectation stamp
        ->and($panel->json('sections'))->toBe(['rollups', 'recent_tickets', 'betting_fields', 'notes', 'files'])
        ->and(count($panel->json('identities')))->toBe(1)
        ->and($panel->json('identities.0.type'))->toBe('telegram')
        ->and($panel->json('identities.0.source'))->toBe('channel_auto')
        ->and($panel->json('identities.0.first_seen_at'))->toBeString()
        ->and($panel->json('identities.0.username'))->toBe('@fixture_panel_new')
        ->and($panel->json('identities.0.username_history.0.old'))->toBe('@fixture_panel_old');

    $tickets = test()->actingAs($viewer, 'staff')->getJson("https://localhost/api/staff/customers/{$customerId}/panel/tickets");
    $tickets->assertOk();
    expect(count($tickets->json('tickets')))->toBe(5);
});

it('SEC-01 THROUGH THE PANEL PATH: the unpermitted header carries NO rg key at all — absence, not redaction (the step-4 proof, re-asserted on this door)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $customerId)->update([
        'rg_flag' => true, 'rg_flag_set_at' => '2026-06-01 12:00:00+00', 'rg_flag_reason' => 'fixture',
    ]);
    $unpermitted = customersScopedActor('All', 'S08 Panel NoRg');

    $panel = test()->actingAs($unpermitted, 'staff')->getJson("https://localhost/api/staff/customers/{$customerId}/panel");
    $panel->assertOk();

    $keys = array_keys((array) $panel->json('header'));
    foreach ($keys as $key) {
        expect(str_contains($key, 'rg_'))->toBeFalse("an rg-prefixed key surfaced on the unpermitted panel: {$key}");
    }
});

it('C-10: a cross-tenant panel fetch is a 404, indistinguishable from missing', function (): void {
    $tenantB = createTenant();
    $foreign = enforcedCustomer($tenantB->identifier(), '2026-06-01 10:00:00+00');

    $tenantA = createTenant();
    activateTenant($tenantA);
    $viewer = customersScopedActor('All', 'S08 Panel XT');

    test()->actingAs($viewer, 'staff')
        ->getJson("https://localhost/api/staff/customers/{$foreign}/panel")
        ->assertStatus(404);
});

it('BR-05 SERVER HALF: a tombstoned customer\'s panel carries merged_into_id — the client redirects, never a 404 (the profile-page route is step 11\'s)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $survivor = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $merged = enforcedCustomer($tenantId, '2026-06-01 10:05:00+00');
    DB::table('customers')->where('id', $merged)->update(['merged_into_id' => $survivor]);
    $viewer = customersScopedActor('All', 'S08 Panel Tomb');

    $panel = test()->actingAs($viewer, 'staff')->getJson("https://localhost/api/staff/customers/{$merged}/panel");
    $panel->assertOk();
    expect($panel->json('header.merged_into_id'))->toBe($survivor);
});
