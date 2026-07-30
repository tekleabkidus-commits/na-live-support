<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * The two layers are INDEPENDENT, proven by disabling each in turn
 * (Doc 01 NFR-01, SEC-01, SS-02).
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * WHY THIS FILE IS THE POINT OF THE WHOLE MODULE.
 *
 * Every other isolation test runs with BOTH layers active, so every one of them
 * passes if either layer works. That is comforting and nearly meaningless: a suite
 * that is green because the Eloquent scope filters everything would look identical
 * to a suite that is green because RLS does. NFR-01 does not ask for isolation — it
 * asks for isolation that SURVIVES A DEFECT: "a defect that omits an
 * application-level tenant filter SHALL still be contained by row-level security".
 *
 * So each test here breaks one layer on purpose and asserts the other still holds
 * alone. If both tests pass, the layers are genuinely independent. If one fails, the
 * two layers were one layer wearing two hats, and NFR-01 was never satisfied no
 * matter how green everything else was.
 *
 * Doc 01 SS-02 describes this as a success scenario: "A deliberate cross-tenant
 * query in a code review exercise is blocked by both scope and RLS in the isolation
 * suite."
 * ────────────────────────────────────────────────────────────────────────────────
 */

/**
 * LAYER 2 ALONE — the application filter is removed, RLS must still contain it.
 *
 * `withoutGlobalScope(TenantScope::class)` is a faithful simulation of the defect
 * NFR-01 names. It is exactly what a developer produces by writing
 * `DB::table('users')`, a raw reporting join, or a `whereRaw` subquery — code paths
 * Eloquent's scope never sees. Rather than pretend to be surprised by that
 * possibility, this test creates it.
 */
it('contains cross-tenant reads with the Eloquent scope REMOVED (RLS alone)', function () {
    $a = createTenant();
    $b = createTenant();

    $userA = asTenant($a, fn () => User::factory()->create());
    $userB = asTenant($b, fn () => User::factory()->create());

    bindTenant($a);

    // Layer 1 disabled, deliberately.
    $withoutScope = User::query()->withoutGlobalScope(TenantScope::class)->pluck('id')->all();

    // NOTE on assertion style: `toContain()` is VARIADIC in Pest — extra arguments
    // are additional needles, not a failure message. Passing a message there asserts
    // that the array contains the message text, which is how the control in the
    // second test below failed while the behaviour was entirely correct. Session 0
    // hit the same trap in StackSmokeTest. So membership is checked with in_array()
    // and asserted with toBeTrue/toBeFalse, which do take a message.
    expect(in_array((string) $userA->getKey(), $withoutScope, true))->toBeTrue(
        'the fixture is wrong: tenant A\'s own row is not visible to tenant A',
    );
    expect(in_array((string) $userB->getKey(), $withoutScope, true))->toBeFalse(
        'SEV-1: with the Eloquent scope removed, tenant B\'s row was visible. RLS is not containing '
        .'the application layer\'s defects, so NFR-01 does not hold and the second layer is decorative.',
    );

    // Same conclusion by the bluntest possible route: raw SQL, no Eloquent at all.
    $raw = DB::table('users')->pluck('id')->all();
    expect(in_array((string) $userB->getKey(), $raw, true))->toBeFalse(
        'SEV-1: raw SQL as the app role returned another tenant\'s row',
    );

    // And the write direction: RLS's WITH CHECK, with no model events involved.
    $failed = null;
    try {
        DB::transaction(fn () => DB::table('users')->insert([
            'id' => (string) Illuminate\Support\Str::uuid7(),
            'tenant_id' => (string) $b->getKey(),
            'name' => 'forged',
            'email' => 'forged@fixture.invalid',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    } catch (Illuminate\Database\QueryException $e) {
        $failed = $e->getMessage();
    }

    expect($failed)->toContain('violates row-level security policy');
});

/**
 * LAYER 1 ALONE — the database is made to permit the WRONG tenant, and the Eloquent
 * scope must still contain the read.
 *
 * See the long comment inside for why Layer 2 is neutralised by pointing it at the
 * wrong tenant rather than by `ALTER TABLE … DISABLE ROW LEVEL SECURITY`. Short
 * version: the DDL deadlocks against the test's own row locks and hangs the suite.
 * The DDL variant was run by hand as a one-off demonstration and its output is in the
 * session report; this is the version that can live in CI.
 */
it('contains cross-tenant reads with RLS PERMITTING the other tenant (Eloquent scope alone)', function () {
    $a = createTenant();
    $b = createTenant();

    $userA = asTenant($a, fn () => User::factory()->create());
    $userB = asTenant($b, fn () => User::factory()->create());

    // HOW LAYER 2 IS NEUTRALISED HERE, AND WHY NOT WITH `ALTER TABLE … DISABLE`.
    //
    // The literal sabotage is DDL, and DDL on `users` needs ACCESS EXCLUSIVE, which
    // conflicts with the row locks this test's own INSERTs hold inside the harness
    // transaction: the ALTER waits for a transaction that is waiting for the ALTER,
    // and the suite HANGS rather than failing. (Committing the fixtures first and
    // cleaning up by hand was tried; the lock still blocked.) A test that can hang
    // the pipeline is not a test.
    //
    // This achieves the same thing hermetically. Layer 1 is told the tenant is A;
    // Layer 2 is told the tenant is B. The database is therefore actively PERMITTING
    // tenant B's rows — RLS is not merely off, it is working against us — and the
    // only thing that can keep B's rows invisible is the Eloquent scope.
    //
    // The control below is what makes this airtight: with the scope removed, B's rows
    // DO come through, proving the database really was permitting them. So the pair
    // of assertions says exactly what NFR-01 needs: containment survived with the
    // database offering no protection at all.
    //
    // The DDL version was still run by hand as a one-off demonstration for the
    // record; see the session report.
    bindTenant($a);                                   // Layer 1 → tenant A
    app(App\Support\Tenancy\RlsSession::class)->set((string) $b->getKey()); // Layer 2 → tenant B

    $visible = User::query()->pluck('id')->all();

    expect(in_array((string) $userB->getKey(), $visible, true))->toBeFalse(
        'SEV-1: with the database permitting tenant B, the Eloquent scope did not keep B\'s rows out. '
        .'The two layers are not independent — Layer 1 was relying on the database all along.',
    );

    // The control. Remove the scope and B's rows appear, which proves the database
    // was permitting them and that the assertion above is about the scope doing work
    // rather than about an empty table.
    $unscoped = User::query()->withoutGlobalScope(TenantScope::class)->pluck('id')->all();

    expect(in_array((string) $userB->getKey(), $unscoped, true))->toBeTrue(
        'the control failed: the database was not actually permitting tenant B, so this test proves '
        .'nothing about the Eloquent scope',
    );
    expect(in_array((string) $userA->getKey(), $unscoped, true))->toBeFalse(
        'with the session variable set to B, A\'s rows should be the ones hidden — if both are visible '
        .'the RLS policy is not applying at all and the setup is wrong',
    );
});
