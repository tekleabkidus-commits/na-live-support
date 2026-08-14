<?php

declare(strict_types=1);

use App\Domain\Customers\Identity\DeprecatedColumnDropGate;
use App\Domain\Customers\Identity\LegacyIdentityBackfill;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/*
|--------------------------------------------------------------------------
| SCHEMA AMENDMENT 2 correction B2 + Note 1 — the legacy backfill, proven
|--------------------------------------------------------------------------
|
| EC-02 documents a family sharing one phone as a REALITY, so the collision is
| expected data, not a hypothetical: the migration must complete
| deterministically, preserve every value, and report the pair for human
| consolidation (FR-09's "humans consolidate" applied to legacy data). The
| drop gate (Note 1) refuses any future column drop that cannot prove
| preservation.
*/

// legacyCustomer()/legacyIdentity() live in tests/Pest.php (shared with
// IdentityInvariantsTest so each suite runs standalone).

// The ENFORCE migration closed the legacy window; this suite exists to prove
// how legacy shapes were handled, so it re-opens the window per test (the
// migrator-connection toggle — see tests/Pest.php).
beforeEach(function (): void {
    legacyIdentityShapeAllow();
});

it('gives a shared phone to the OLDER customer, preserves the loser value in a conflict row, and both values stay retrievable', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $older = legacyCustomer($tenant->id, '2026-06-01 10:00:00+00', '+251900001111', '@older_fixture');
        $newer = legacyCustomer($tenant->id, '2026-07-01 10:00:00+00', '+251900001111', '@newer_fixture');
        legacyIdentity($tenant->id, $older, 'telegram', 'fixture-tg-1', '2026-06-01 10:00:00+00');
        legacyIdentity($tenant->id, $newer, 'telegram', 'fixture-tg-2', '2026-07-01 10:00:00+00');

        (new LegacyIdentityBackfill)->run('pgsql');

        // Exactly ONE phone identity, on the keeper (oldest created_at).
        $phoneIdentities = DB::table('customer_identities')->where('type', 'phone')->get();
        expect($phoneIdentities)->toHaveCount(1)
            ->and($phoneIdentities[0]->customer_id)->toBe($older)
            ->and($phoneIdentities[0]->value)->toBe('+251900001111');

        // The loser's value is PRESERVED twice over: the deprecated column
        // survives the contract window AND the conflict row is the permanent home.
        $loserRow = DB::table('customers')->where('id', $newer)->first();
        expect($loserRow->phone)->toBe('+251900001111');

        $conflicts = DB::table('customer_identity_conflicts')->get();
        expect($conflicts)->toHaveCount(1)
            ->and($conflicts[0]->type)->toBe('phone')
            ->and($conflicts[0]->value)->toBe('+251900001111')
            ->and($conflicts[0]->keeper_customer_id)->toBe($older)
            ->and($conflicts[0]->loser_customer_id)->toBe($newer)
            ->and($conflicts[0]->resolved_at)->toBeNull();

        // The typed channel identities: value byte-equal to external id,
        // first_seen_at byte-equal to the row's own created_at (the amendment's
        // copy discipline), created_source derived.
        $tg = DB::table('customer_identities')->where('customer_id', $older)->where('type', 'telegram')->first();
        expect($tg->value)->toBe('fixture-tg-1')
            ->and($tg->first_seen_at)->toBe($tg->created_at)
            ->and($tg->source)->toBe('channel_auto');

        expect(DB::table('customers')->where('id', $older)->value('created_source'))->toBe('telegram');
    });
});

it('breaks a created_at tie by the LOWEST UUIDv7 — deterministic, never a coin flip (R-23 family principle)', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $a = (string) new UuidV7;
        $b = (string) new UuidV7;
        [$low, $high] = strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];

        $sameInstant = '2026-06-15 09:00:00+00';
        legacyCustomer($tenant->id, $sameInstant, '+251900002222', null, $high);
        legacyCustomer($tenant->id, $sameInstant, '+251900002222', null, $low);
        legacyIdentity($tenant->id, $low, 'telegram', 'fixture-tg-low', $sameInstant);
        legacyIdentity($tenant->id, $high, 'telegram', 'fixture-tg-high', $sameInstant);

        (new LegacyIdentityBackfill)->run('pgsql');

        expect(DB::table('customer_identities')->where('type', 'phone')->value('customer_id'))->toBe($low)
            ->and(DB::table('customer_identity_conflicts')->value('loser_customer_id'))->toBe($high);
    });
});

it('is idempotent — a second run changes nothing', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $older = legacyCustomer($tenant->id, '2026-06-01 10:00:00+00', '+251900003333', '@fixture_a');
        $newer = legacyCustomer($tenant->id, '2026-07-01 10:00:00+00', '+251900003333');
        legacyIdentity($tenant->id, $older, 'telegram', 'fixture-tg-3', '2026-06-01 10:00:00+00');
        legacyIdentity($tenant->id, $newer, 'telegram', 'fixture-tg-4', '2026-07-01 10:00:00+00');

        $backfill = new LegacyIdentityBackfill;
        $backfill->run('pgsql');
        $after = [
            DB::table('customer_identities')->count(),
            DB::table('customer_identity_conflicts')->count(),
        ];

        $backfill->run('pgsql');
        expect([
            DB::table('customer_identities')->count(),
            DB::table('customer_identity_conflicts')->count(),
        ])->toBe($after);
    });
});

it('REFUSES loudly on an unmapped channel, naming it', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $customer = legacyCustomer($tenant->id, '2026-06-01 10:00:00+00');
        legacyIdentity($tenant->id, $customer, 'smoke', 'fixture-x-1', '2026-06-01 10:00:00+00');

        expect(fn () => (new LegacyIdentityBackfill)->run('pgsql'))
            ->toThrow(RuntimeException::class, 'smoke');
    });
});

it('mirrors customers.username into the telegram identity metadata — the drop gate\'s second half', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $customer = legacyCustomer($tenant->id, '2026-06-01 10:00:00+00', null, '@keeps_history');
        legacyIdentity($tenant->id, $customer, 'telegram', 'fixture-tg-5', '2026-06-01 10:00:00+00');

        (new LegacyIdentityBackfill)->run('pgsql');

        $metadata = json_decode((string) DB::table('customer_identities')->where('customer_id', $customer)->value('metadata'), true);
        expect($metadata['username'] ?? null)->toBe('@keeps_history');
    });
});

it('drop gate: green after the backfill, RED for a value with no permanent home — and app cannot delete the home (ruling 2 physics)', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $older = legacyCustomer($tenant->id, '2026-06-01 10:00:00+00', '+251900004444');
        $newer = legacyCustomer($tenant->id, '2026-07-01 10:00:00+00', '+251900004444');
        legacyIdentity($tenant->id, $older, 'telegram', 'fixture-tg-6', '2026-06-01 10:00:00+00');
        legacyIdentity($tenant->id, $newer, 'telegram', 'fixture-tg-7', '2026-07-01 10:00:00+00');

        $gate = new DeprecatedColumnDropGate;

        // NEGATIVE CONTROL FIRST: before the backfill runs, both phone values
        // lack any permanent home — the gate must refuse, naming rows.
        expect($gate->verify('pgsql'))->not->toBeEmpty('the gate passed a state where dropping the column would destroy values');

        (new LegacyIdentityBackfill)->run('pgsql');

        // Healthy state: every value has its home; the drop would be safe.
        expect($gate->verify('pgsql'))->toBe([]);

        // The home itself is UNDELETABLE by the app role — ruling 2's grant,
        // exercised live (the migration-level sabotage #14 proves the same
        // physics from the schema side). SAVEPOINT-wrapped so the refused
        // statement cannot poison the harness transaction.
        expect(fn () => DB::transaction(fn () => DB::table('customer_identity_conflicts')->delete()))
            ->toThrow(QueryException::class, '42501');
    });
});

it('RULING 1: a legacy-shaped straggler written AFTER the first backfill is handled by enforce under the same keeper policy, then nothing is left un-typed', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $older = legacyCustomer($tenant->id, '2026-06-01 10:00:00+00', '+251900005566');
        legacyIdentity($tenant->id, $older, 'telegram', 'fixture-tg-r1a', '2026-06-01 10:00:00+00');

        (new LegacyIdentityBackfill)->run('pgsql');

        // The straggler: the S20 writer minted this AFTER the 1b backfill —
        // NULL-typed, sharing the first customer's phone, invisible to the
        // unique index (NULLs distinct). Exactly ruling 1's scenario.
        $straggler = legacyCustomer($tenant->id, '2026-08-01 10:00:00+00', '+251900005566');
        legacyIdentity($tenant->id, $straggler, 'telegram', 'fixture-tg-r1b', '2026-08-01 10:00:00+00');

        (new App\Domain\Customers\Identity\LegacyIdentityEnforce)->run('pgsql');

        // The straggler is typed; the shared phone resolved under the SAME
        // keeper policy (older keeps it, straggler gets the conflict row);
        // zero un-typed rows remain for NOT NULL to refuse.
        $stragglerTg = DB::table('customer_identities')->where('customer_id', $straggler)->where('type', 'telegram')->first();
        expect($stragglerTg)->not->toBeNull()
            ->and($stragglerTg->value)->toBe('fixture-tg-r1b')
            ->and(DB::table('customer_identities')->where('type', 'phone')->value('customer_id'))->toBe($older)
            ->and(DB::table('customer_identity_conflicts')->where('loser_customer_id', $straggler)->count())->toBe(1)
            ->and(DB::table('customer_identities')->whereNull('type')->count())->toBe(0)
            ->and(DB::table('customers')->whereNull('created_source')->count())->toBe(0);
    });
});

it('enforce re-normalizes a backfilled phone to E.164 (ADR 0014), preserving the ORIGINAL spelling in metadata.raw and the drop gate\'s equality', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $customer = legacyCustomer($tenant->id, '2026-06-01 10:00:00+00', '0912344417');
        legacyIdentity($tenant->id, $customer, 'telegram', 'fixture-tg-n1', '2026-06-01 10:00:00+00');

        (new LegacyIdentityBackfill)->run('pgsql');
        (new App\Domain\Customers\Identity\LegacyIdentityEnforce)->run('pgsql');

        $identity = DB::table('customer_identities')->where('type', 'phone')->first();
        $metadata = (array) json_decode((string) $identity->metadata, true);

        expect($identity->value)->toBe('+251912344417')
            ->and($metadata['raw'] ?? null)->toBe('0912344417')
            ->and(DB::table('customers')->where('id', $customer)->value('phone'))->toBe('+251912344417')
            ->and((new DeprecatedColumnDropGate)->verify('pgsql'))->toBe([]);
    });
});

it('enforce REFUSES when normalization would fuse two customers — merging is a human decision, never a migration\'s', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function () use ($tenant): void {
        $a = legacyCustomer($tenant->id, '2026-06-01 10:00:00+00', '+251912344417');
        $b = legacyCustomer($tenant->id, '2026-07-01 10:00:00+00', '0912344417');
        legacyIdentity($tenant->id, $a, 'telegram', 'fixture-tg-c1', '2026-06-01 10:00:00+00');
        legacyIdentity($tenant->id, $b, 'telegram', 'fixture-tg-c2', '2026-07-01 10:00:00+00');

        // Two RAW-distinct values → two phone identities at backfill; the
        // second normalizes onto the first → enforce must stop, loudly.
        (new LegacyIdentityBackfill)->run('pgsql');

        expect(fn () => (new App\Domain\Customers\Identity\LegacyIdentityEnforce)->run('pgsql'))
            ->toThrow(RuntimeException::class, 'collide');
    });
});
