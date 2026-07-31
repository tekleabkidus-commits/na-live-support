<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The pre-tenant lookups, proven narrow (Doc 01 inherited constraint #2; Doc 02
 * FR-01/FR-18/SEC-01; owner rulings Q1 and the Sanctum-under-RLS row).
 *
 * Three surfaces in this platform must read the database BEFORE a tenant is
 * known, because the thing they read is what identifies the tenant:
 *
 *   resolve_tenant_key      channel/API keys        (Doc 01, already proven)
 *   resolve_staff_login     staff email at login    (this suite)
 *   resolve_api_token       bearer tokens           (this suite)
 *
 * The owner's ruling on the third was explicit: a narrow SECURITY DEFINER lookup
 * is acceptable, an RLS exemption for the table is not. So what these tests have
 * to establish is not "the lookup works" — that is the easy half — but that it
 * hands the caller NOTHING GENERAL. Specifically, for each door:
 *
 *   1. It answers with no tenant bound (otherwise authentication is impossible).
 *   2. A DIRECT read of the same table with no tenant bound still fails — i.e.
 *      the door is the only pre-tenant path, and RLS was not quietly relaxed.
 *   3. Using the door grants no lasting visibility: immediately afterwards, the
 *      caller still cannot read another tenant's rows.
 *   4. It matches exactly, so it cannot be walked to enumerate the space.
 *   5. It returns no secret beyond what verification requires.
 *
 * If any of these fails, the door has become a bypass.
 */
function twoTenantsWithUsers(): array
{
    $tenantA = createTenant();
    $userA = asTenant($tenantA, fn (): User => User::factory()->create([
        'email' => 'shared@fixture.example',
        'password' => Hash::make('correct-horse-battery-staple-a'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenantA);

    $tenantB = createTenant();
    $userB = asTenant($tenantB, fn (): User => User::factory()->create([
        'email' => 'shared@fixture.example',
        'password' => Hash::make('correct-horse-battery-staple-b'),
        'password_set_at' => now(),
    ]));
    activateTenant($tenantB);

    // Both layers cleared: this is what a real unauthenticated request looks
    // like, and BUILD-STATUS records a Doc 01 test that passed for the wrong
    // reason because a fixture had left the session variable populated.
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenantA, $userA, $tenantB, $userB];
}

it('resolves the same email in TWO tenants with no tenant bound (Q1: multi-candidate is the steady state)', function () {
    [$tenantA, $userA, $tenantB, $userB] = twoTenantsWithUsers();

    $rows = DB::select('SELECT * FROM resolve_staff_login(?)', ['shared@fixture.example']);

    // The whole reason the function returns a SET rather than a row: Doc 16 §19
    // puts per-tenant staff domains in FUTURE expansion, so one address legitimately
    // existing in several tenants is v1's permanent shape, not a transient wrinkle.
    expect($rows)->toHaveCount(2);

    $byTenant = collect($rows)->keyBy('tenant_id');
    expect($byTenant)->toHaveKey($tenantA->id)
        ->and($byTenant)->toHaveKey($tenantB->id)
        ->and($byTenant[$tenantA->id]->user_id)->toBe($userA->id)
        ->and($byTenant[$tenantB->id]->user_id)->toBe($userB->id)
        // Each candidate carries its own credential, so verification can pick.
        ->and($byTenant[$tenantA->id]->password_hash)->not->toBe($byTenant[$tenantB->id]->password_hash);
});

it('is the ONLY pre-tenant path: a direct read of users with no tenant bound still fails', function () {
    twoTenantsWithUsers();

    // If this ever starts succeeding, RLS has been relaxed on `users` and the
    // function is decoration rather than a door.
    expect(fn () => DB::select('SELECT id FROM users WHERE email = ?', ['shared@fixture.example']))
        ->toThrow(QueryException::class);
});

it('grants no lasting cross-tenant visibility after it is used', function () {
    [$tenantA, , $tenantB, $userB] = twoTenantsWithUsers();

    // Use the door…
    $rows = DB::select('SELECT * FROM resolve_staff_login(?)', ['shared@fixture.example']);
    expect($rows)->toHaveCount(2);

    // …then bind tenant A, exactly as a successful login would, and confirm the
    // caller is back inside ordinary RLS with no residue from the lookup.
    app(RlsSession::class)->set($tenantA->id);

    $visible = DB::select('SELECT id FROM users WHERE id = ?', [$userB->id]);
    expect($visible)->toBeEmpty('SEV-1: tenant B\'s user is readable after a pre-tenant lookup bound tenant A');
});

it('matches an exact address only — it cannot be walked to enumerate accounts', function () {
    twoTenantsWithUsers();

    foreach (['shared@fixture.exampl', 'shared@fixture.example ', '%@fixture.example', 'SHARED@FIXTURE.EXAMPLE_'] as $probe) {
        $rows = DB::select('SELECT * FROM resolve_staff_login(?)', [$probe]);

        expect($rows)->toBeEmpty(sprintf(
            'SEV-1: "%s" matched — the lookup is not an exact match and can enumerate the address space.',
            $probe,
        ));
    }

    // Case-insensitivity is intentional (emails are case-insensitive in practice
    // and Fortify lowercases usernames), and is NOT pattern matching.
    expect(DB::select('SELECT * FROM resolve_staff_login(?)', ['SHARED@FIXTURE.EXAMPLE']))->toHaveCount(2);
});

it('returns no 2FA secret and no recovery material to the login path', function () {
    [$tenantA, $userA] = twoTenantsWithUsers();

    asTenant($tenantA, function () use ($userA): void {
        $userA->forceFill([
            'two_factor_secret' => 'FIXTURESECRETVALUE',
            'two_factor_confirmed_at' => now(),
        ])->save();
    });
    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $row = DB::select('SELECT * FROM resolve_staff_login(?)', ['shared@fixture.example'])[0];
    $returned = json_encode((array) $row);

    // The login path needs to KNOW 2FA is on; it must never receive the secret
    // itself, which is only ever used inside the verifier (BR-02).
    expect((array) $row)->not->toHaveKey('two_factor_secret')
        ->and($returned)->not->toContain('FIXTURESECRET')
        ->and(property_exists($row, 'two_factor_enabled'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The bearer-token door (owner ruling: state how the api guard finds the row)
|--------------------------------------------------------------------------
*/

function tokenRow(Tenant $tenant, User $user, string $hash, array $overrides = []): void
{
    asTenant($tenant, function () use ($tenant, $user, $hash, $overrides): void {
        DB::table('personal_access_tokens')->insert(array_merge([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'fixture token',
            'token' => $hash,
            'abilities' => json_encode(['settings.view']),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    });
}

it('resolves a bearer token with no tenant bound, and refuses a direct read of the same table', function () {
    [$tenantA, $userA] = twoTenantsWithUsers();
    $hash = hash('sha256', 'fixture-plaintext-token');
    tokenRow($tenantA, $userA, $hash);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $rows = DB::select('SELECT * FROM resolve_api_token(?)', [$hash]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->tenant_id)->toBe($tenantA->id)
        ->and($rows[0]->tokenable_id)->toBe($userA->id)
        ->and((bool) $rows[0]->expired)->toBeFalse()
        ->and((bool) $rows[0]->revoked)->toBeFalse()
        // BR-04/EC-05 in the same round trip, so the guard cannot forget to ask.
        ->and((bool) $rows[0]->user_disabled)->toBeFalse();

    // The table is RLS'd, not exempt — the ruling's non-negotiable half.
    expect(fn () => DB::select('SELECT id FROM personal_access_tokens WHERE token = ?', [$hash]))
        ->toThrow(QueryException::class);
});

it('reports a revoked, expired or disabled-owner token as such rather than hiding it', function () {
    [$tenantA, $userA] = twoTenantsWithUsers();

    $revokedHash = hash('sha256', 'revoked-token');
    tokenRow($tenantA, $userA, $revokedHash, ['revoked_at' => now()->subMinute()]);

    $expiredHash = hash('sha256', 'expired-token');
    tokenRow($tenantA, $userA, $expiredHash, ['expires_at' => now()->subMinute()]);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    // FR-20 needs to distinguish 401 invalid_token from 401 token_expired, so the
    // door reports the REASON; hiding it would collapse two distinct responses.
    expect((bool) DB::select('SELECT * FROM resolve_api_token(?)', [$revokedHash])[0]->revoked)->toBeTrue()
        ->and((bool) DB::select('SELECT * FROM resolve_api_token(?)', [$expiredHash])[0]->expired)->toBeTrue();
});

it('answers an unknown token with zero rows, indistinguishable from any other miss', function () {
    twoTenantsWithUsers();

    expect(DB::select('SELECT * FROM resolve_api_token(?)', [hash('sha256', 'never-issued')]))->toBeEmpty();
});

it('keeps both doors owned by the NOLOGIN key_resolver role (ADR 0003: bypass scoped to a body)', function () {
    /** @var list<object{proname: string, owner: string, canlogin: bool}> $rows */
    $rows = DB::connection('pgsql_migrator')->select("
        SELECT p.proname, r.rolname AS owner, r.rolcanlogin AS canlogin
        FROM pg_proc p
        JOIN pg_roles r ON r.oid = p.proowner
        WHERE p.proname IN ('resolve_staff_login', 'resolve_api_token', 'resolve_auth_action_token')
    ");

    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        expect($row->owner)->toBe('key_resolver', sprintf(
            'SEV-1: %s is owned by %s. A SECURITY DEFINER function runs as its owner — '
            .'any other owner either re-enters RLS (breaking it) or hands a login role a bypass.',
            $row->proname,
            $row->owner,
        ))->and((bool) $row->canlogin)->toBeFalse(
            'SEV-1: key_resolver can log in — its BYPASSRLS must be reachable only through these function bodies',
        );
    }
});
