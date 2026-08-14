<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/*
|--------------------------------------------------------------------------
| Doc 08 AC-02 (schema half) / BR-04 / FR-04 — one identity, one customer
|--------------------------------------------------------------------------
|
| The (tenant, type, value) uniqueness enforced AT THE DATABASE: a direct-SQL
| duplicate fails on the index itself, no application layer involved. This is
| the invariant that makes merge/unlink meaningful (BR-04) and the arbiter the
| step-2 resolver's ON CONFLICT rides. SABOTAGE #3 (the index dropped at
| migration level) turns the first test red.
|
| The FR-09 duplicate-phone-block half of AC-02 lands at step 5
| (LinkUnlinkTest) — the traceability row carries the split.
*/

function typedIdentityRow(string $tenantId, string $customerId, string $type, string $value): array
{
    $id = (string) new UuidV7;

    return [
        'id' => $id,
        'tenant_id' => $tenantId,
        'customer_id' => $customerId,
        // Legacy NOT NULL columns get row-unique synthetic values so the ONLY
        // index a (type, value) duplicate can trip is the NEW one — the first
        // run collided on the legacy match index instead and proved the wrong
        // guard (filed).
        'channel' => $type,
        'external_user_id' => $value.'-'.$id,
        'type' => $type,
        'value' => $value,
        'source' => 'staff',
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('refuses a direct-SQL duplicate (tenant, type, value) on the unique index itself — AC-02, BR-04', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $a = legacyCustomer($tenant->id, '2026-06-01 10:00:00+00');
        $b = legacyCustomer($tenant->id, '2026-06-02 10:00:00+00');

        DB::table('customer_identities')->insert(typedIdentityRow($tenant->id, $a, 'phone', '+251900005555'));

        try {
            // SAVEPOINT (nested transaction): a bare failed insert would abort
            // the harness transaction and poison every later statement — the
            // same physics the S20 resolver's docblock records.
            DB::transaction(function () use ($tenant, $b): void {
                DB::table('customer_identities')->insert(typedIdentityRow($tenant->id, $b, 'phone', '+251900005555'));
            });
            $this->fail('the duplicate (tenant, type, value) insert was ACCEPTED — BR-04\'s index is gone');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23505')
                ->and($e->getMessage())->toContain('customer_identities_type_value_unique');
        }

        // Same value, DIFFERENT type: legal — the tuple is (type, value).
        DB::table('customer_identities')->insert(typedIdentityRow($tenant->id, $b, 'email', '+251900005555'));
        expect(DB::table('customer_identities')->where('value', '+251900005555')->count())->toBe(2);
    });
});

it('lets legacy NULL-typed rows coexist during the expand window (the C-12 posture, documented)', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $a = legacyCustomer($tenant->id, '2026-06-01 10:00:00+00');
        legacyIdentity($tenant->id, $a, 'telegram', 'fixture-null-1', '2026-06-01 10:00:00+00');
        legacyIdentity($tenant->id, $a, 'fake', 'fixture-null-2', '2026-06-01 10:00:00+00');

        // NULLs are distinct under the full unique index — the still-live S20
        // resolver keeps working until step 2 rewires it, which is the point.
        expect(DB::table('customer_identities')->whereNull('type')->count())->toBe(2);
    });
});
