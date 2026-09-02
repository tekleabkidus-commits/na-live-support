<?php

declare(strict_types=1);

use App\Domain\Customers\Fields\CustomerFieldValues;
use App\Domain\Customers\Fields\FieldRetired;
use App\Domain\Fields\DefinitionWriter;
use App\Domain\Fields\Seeding\CustomerFieldSeeder;
use App\Domain\Fields\ValueWriter;
use App\Jobs\Fields\CustomerFieldSeederConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| PACKET P-FIELDS LANDED (S12 batch D; countersigned at the batch-C acceptance)
| — Doc 08's CustomerFieldValues::definitions() reads Doc 12's REAL rows
|--------------------------------------------------------------------------
|
| The A-53/HoursContract swap shape: the real source FIRST (field_definitions,
| entity=customer), the [D12] seam fixture ONLY when the tenant holds no rows
| (the PRE state, kept as the control — Doc 08's own suites run on it byte-
| untouched). The betting trio arrives as REAL rows at provisioning (the
| tenant.provisioned consumer). The three controls the countersign named:
| (1) PRE on the fixture; (2) POST — a NEW definition accepts a value through
| BOTH doors, a DEACTIVATED real row refuses as FieldRetired (EC-09 from the
| real source); (3) the duplicate-player_id hint (SEC-05) unchanged on the trio.
*/

function provisionCustomerTrio(string $tenantId): array
{
    $id = (string) Str::uuid7();
    DB::table('outbox')->insert(['id' => $id, 'tenant_id' => $tenantId, 'event_id' => $id, 'name' => 'tenant.provisioned', 'schema_version' => 1, 'payload' => json_encode(['tenant_id' => $tenantId])]);
    DB::table('events')->insertOrIgnore(['id' => $id, 'tenant_id' => $tenantId, 'name' => 'tenant.provisioned', 'schema_version' => 1, 'payload' => json_encode(['tenant_id' => $tenantId])]);
    (new CustomerFieldSeederConsumer($id))->handle(app(CustomerFieldSeeder::class));

    return [$id];
}

it('control 1 (PRE): with NO customer field_definitions rows Doc 08 reads the [D12] fixture byte-for-byte — four keys, loyalty_tier retired', function (): void {
    ticketFixture('open');
    expect(DB::table('field_definitions')->where('entity', 'customer')->count())->toBe(0);
    /** @var array{fields: list<array<string, mixed>>} $fixture */
    $fixture = json_decode((string) file_get_contents(base_path('fixtures/customers/field-definitions.json')), true, 8, JSON_THROW_ON_ERROR);
    expect(CustomerFieldValues::definitions())->toBe($fixture['fields'])
        ->and(array_column(CustomerFieldValues::definitions(), 'key'))->toBe(['betting_username', 'player_id', 'brand', 'loyalty_tier']);
});

it('the provisioning consumer seeds the trio as REAL rows (system actor, en+am labels), a redelivery seeds nothing twice, and Doc 08 then reads the rows — same keys, same i18n label KEYS; the fixture is no longer read', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    provisionCustomerTrio($tenant->identifier());
    expect(DB::table('field_definitions')->where('entity', 'customer')->orderBy('position')->pluck('key')->all())->toBe(CustomerFieldSeeder::TRIO);

    // Redelivery: the seeder answers [] and the count holds (C-09 / the keys-never-reused unique as backstop).
    expect(app(CustomerFieldSeeder::class)->seed())->toBe([]);
    provisionCustomerTrio($tenant->identifier());
    expect(DB::table('field_definitions')->where('entity', 'customer')->count())->toBe(3);

    $definitions = CustomerFieldValues::definitions();
    expect(array_column($definitions, 'key'))->toBe(CustomerFieldSeeder::TRIO, 'the fixture\'s retired loyalty_tier must NOT appear once real rows exist')
        ->and(array_column($definitions, 'label_i18n'))->toBe(['customers.fields.betting_username', 'customers.fields.player_id', 'customers.fields.brand'])
        ->and(array_unique(array_column($definitions, 'retired')))->toBe([false])
        ->and(array_unique(array_column($definitions, 'type')))->toBe(['text']);
    foreach ($definitions as $definition) {
        foreach (['en', 'am'] as $lang) {
            expect(trans($definition['label_i18n'], [], $lang))->not->toBe($definition['label_i18n']);
        }
    }
    // The real row's label carries BOTH languages (the seeder read the platform strings).
    /** @var array<string, string> $label */
    $label = json_decode((string) DB::table('field_definitions')->where('entity', 'customer')->where('key', 'player_id')->value('label'), true, 4);
    expect($label['en'])->toBe(trans('customers.fields.player_id', [], 'en'))
        ->and($label['am'])->toBe(trans('customers.fields.player_id', [], 'am'));

    // Doc 08's door still accepts the trio (control 3's premise): player_id through CustomerFieldValues::set.
    $customerId = (string) DB::table('tickets')->where('id', $ticket->identifier())->value('customer_id');
    $actor = (string) Str::uuid7();
    expect(app(CustomerFieldValues::class)->set($customerId, ['player_id' => 'NB-40219'], $actor))->toBe(['player_id']);
    expect((string) DB::table('customer_field_values')->where('customer_id', $customerId)->where('field_key', 'player_id')->value('value'))->toBe('NB-40219');
});

it('control 3: the duplicate-player_id hint (SEC-05) is unchanged on the REAL trio — count + scope-checked links, names nowhere', function (): void {
    [$tenant] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    provisionCustomerTrio($tenantId);
    $writer = app(CustomerFieldValues::class);
    $actor = (string) Str::uuid7();
    $mine = enforcedCustomer($tenantId, '2026-08-01 10:00:00+00');
    $other = enforcedCustomer($tenantId, '2026-08-01 10:01:00+00');
    $writer->set($mine, ['player_id' => 'NB-SHARED-9'], $actor);
    $writer->set($other, ['player_id' => 'NB-SHARED-9'], $actor);
    $viewer = customersScopedActor('All', 'P-FIELDS Hint All');

    $hint = $writer->duplicateHint($mine, $viewer);
    expect($hint)->not->toBeNull()
        ->and($hint['count'])->toBe(1)
        ->and($hint['links'])->toBe([$other])
        ->and(json_encode($hint))->not->toContain('display_name');
});

it('control 2 (POST): a NEW operator definition accepts a value end-to-end through BOTH doors and lists on the card with its own label; a DEACTIVATED real row refuses as FieldRetired at Doc 08\'s door and as retired at Doc 12\'s (EC-09 from the REAL source)', function (): void {
    [$tenant, , $ticket] = ticketFixture('open');
    provisionCustomerTrio($tenant->identifier());
    $customerId = (string) DB::table('tickets')->where('id', $ticket->identifier())->value('customer_id');
    $actor = (string) Str::uuid7();
    $definitions = app(DefinitionWriter::class);
    $fieldId = $definitions->create(['entity' => 'customer', 'key' => 'vip_source', 'label' => ['en' => 'VIP source', 'am' => 'የቪአይፒ ምንጭ'], 'type' => 'text'], $actor);

    // Both doors accept the NEW key (Doc 12's ValueWriter validates then delegates to Doc 08's door).
    expect(app(CustomerFieldValues::class)->set($customerId, ['vip_source' => 'affiliate'], $actor))->toBe(['vip_source']);
    app(ValueWriter::class)->setCustomerField($customerId, 'vip_source', 'sponsor', $actor);
    expect((string) DB::table('customer_field_values')->where('customer_id', $customerId)->where('field_key', 'vip_source')->value('value'))->toBe('sponsor');

    // The card lists it — an operator-authored label rides as its own text (operator content).
    $card = collect(app(CustomerFieldValues::class)->valuesFor($customerId))->firstWhere('key', 'vip_source');
    expect($card)->not->toBeNull()
        ->and($card['value'])->toBe('sponsor')
        ->and($card['label_i18n'])->toBe('VIP source')
        ->and($card['retired'])->toBeFalse();

    // Deactivate the REAL row → EC-09 from the real source, at BOTH doors; the fact stays, read-only.
    $definitions->deactivate($fieldId, $actor);
    expect(fn () => app(CustomerFieldValues::class)->set($customerId, ['vip_source' => 'changed'], $actor))->toThrow(FieldRetired::class);
    expect(fn () => app(ValueWriter::class)->setCustomerField($customerId, 'vip_source', 'changed', $actor))->toThrow(InvalidArgumentException::class, 'retired');
    $card = collect(app(CustomerFieldValues::class)->valuesFor($customerId))->firstWhere('key', 'vip_source');
    expect($card['retired'])->toBeTrue()
        ->and($card['value'])->toBe('sponsor', 'the definition went, the fact stayed');
});
