<?php

declare(strict_types=1);

use App\Domain\Customers\Fields\CustomerFieldValues;
use App\Domain\Customers\Search\CustomerSearch;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 08 FR-16 / EC-09 (S08 step 9b — amendment addition 9, countersigned)
| — the betting fields: staff-collected values, audit-trail history
| (condition 1), retired read-only, and the search index's player_id half.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

it('FR-16: values write through the door — event carries NAMES only (Q7), audit carries old/new (condition 1: the trail IS the history), A-37 noop on no change', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $editor = ticketActor(['customers.view', 'customers.edit'], 'S08 Fields Editor');

    $write = test()->actingAs($editor, 'staff')
        ->patchJson("https://localhost/api/staff/customers/{$customerId}/fields", [
            'values' => ['betting_username' => 'abebe_bet', 'player_id' => 'NB-40219', 'brand' => 'Na Bet'],
        ]);
    $write->assertOk();
    expect($write->json('changed'))->toEqualCanonicalizing(['betting_username', 'player_id', 'brand']);

    bindTenant($tenant);    // the request lifecycle cleared the RLS binding on its way out
    /** @var object{payload: string} $event */
    $event = DB::table('outbox')->where('name', 'customer.fields_changed')->latest('occurred_at')->first(['payload']);
    expect($event->payload)->toContain('player_id')->not->toContain('NB-40219');
    /** @var object{payload: string} $audit */
    $audit = DB::table('audit_log')->where('action_key', 'customer.fields_changed')->latest('occurred_at')->first(['payload']);
    expect($audit->payload)->toContain('"old"')->toContain('NB-40219');

    // The same values again: the declared A-37 noop — nothing new anywhere.
    $noop = test()->actingAs($editor, 'staff')
        ->patchJson("https://localhost/api/staff/customers/{$customerId}/fields", [
            'values' => ['player_id' => 'NB-40219'],
        ]);
    $noop->assertOk();
    bindTenant($tenant);
    expect($noop->json('changed'))->toBe([])
        ->and(DB::table('audit_log')->where('action_key', 'customer.fields_changed')->count())->toBe(1);
});

it('EC-09: a RETIRED field is READ-ONLY — the write REFUSES (422), but a surviving fact still renders on the card ("the definition went, the fact stayed")', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $editor = ticketActor(['customers.view', 'customers.edit'], 'S08 Fields Retired');

    // The surviving fact, planted as if written before the definition retired
    // (the writer refuses retired keys, so the fixture goes to the storage).
    DB::statement(
        'INSERT INTO customer_field_values (id, tenant_id, customer_id, field_key, value, updated_by) VALUES (?, ?, ?, ?, ?, ?)',
        [(string) Illuminate\Support\Str::uuid7(), $tenantId, $customerId, 'loyalty_tier', 'Gold', $editor->identifier()],
    );

    test()->actingAs($editor, 'staff')
        ->patchJson("https://localhost/api/staff/customers/{$customerId}/fields", ['values' => ['loyalty_tier' => 'Platinum']])
        ->assertStatus(422);

    bindTenant($tenant);
    expect((string) DB::table('customer_field_values')->where('customer_id', $customerId)->where('field_key', 'loyalty_tier')->value('value'))
        ->toBe('Gold', 'the retired value moved — EC-09\'s read-only law is gone');

    $card = test()->actingAs($editor, 'staff')->getJson("https://localhost/api/staff/customers/{$customerId}/panel/betting-fields");
    $card->assertOk();
    $retired = collect($card->json('fields'))->firstWhere('key', 'loyalty_tier');
    expect($retired)->not->toBeNull('the surviving fact vanished from the card')
        ->and($retired['value'])->toBe('Gold')
        ->and($retired['retired'])->toBeTrue();
});

it('T-04\'s player_id half: a stored player_id is SEARCHABLE — the indexer feeds it from the addition-9 storage (the step-8 contractual field, filled)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $customerId)->update(['display_name' => 'Player Search Fixture']);
    $viewer = customersScopedActor('All', 'S08 Fields Search');

    app(CustomerFieldValues::class)->set($customerId, ['player_id' => 'NB-77441'], (string) Illuminate\Support\Str::uuid7());
    indexCustomer($customerId);

    try {
        expect(array_column(app(CustomerSearch::class)->search('NB-77441', $viewer)['hits'], 'id'))
            ->toBe([$customerId], 'a stored player_id does not hit — T-04\'s list is incomplete');
    } finally {
        dropCustomersSearchIndex();
    }
});

it('§14: every seeded field label and the staff-collected/retired micro-labels resolve in en AND am', function (): void {
    foreach (CustomerFieldValues::definitions() as $definition) {
        foreach (['en', 'am'] as $lang) {
            expect(trans($definition['label_i18n'], [], $lang))->not->toBe($definition['label_i18n'], "{$definition['label_i18n']} missing in {$lang}");
        }
    }
    foreach (['customers.fields.staff_collected', 'customers.fields.retired', 'customers.fields.duplicate_hint'] as $key) {
        foreach (['en', 'am'] as $lang) {
            expect(trans($key, [], $lang))->not->toBe($key, "{$key} missing in {$lang}");
        }
    }
});
