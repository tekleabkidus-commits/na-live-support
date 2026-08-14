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

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

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
        $a = enforcedCustomer($tenant->id, '2026-06-01 10:00:00+00');
        $b = enforcedCustomer($tenant->id, '2026-06-02 10:00:00+00');

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

it('REFUSES a legacy NULL-typed insert after enforce — the C-12 window is closed by physics (inverted at step 2, the ADR-0010 pattern)', function (): void {
    // The expand-window characterisation test asserted NULL-typed rows could
    // coexist; the enforce migration inverts it in the same commit, making
    // the old behaviour's absence the regression proof.
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $a = enforcedCustomer($tenant->id, '2026-06-01 10:00:00+00');

        try {
            DB::transaction(function () use ($tenant, $a): void {
                legacyIdentity($tenant->id, $a, 'telegram', 'fixture-null-1', '2026-06-01 10:00:00+00');
            });
            $this->fail('a NULL-typed legacy insert was ACCEPTED post-enforce');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23502');
        }
    });
});
