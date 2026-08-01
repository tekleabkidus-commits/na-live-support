<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * FR-01/FR-04/FR-05/SEC-04: the login flow actually authenticates.
 *
 * THIS SUITE EXISTS BECAUSE THE ENUMERATION SUITE CANNOT PROVE IT. Every AC-01
 * assertion compares two FAILURES against each other, and two failures are
 * identical whether the pipeline is working or completely broken — a login route
 * that rejected everything would pass the whole enumeration suite. So the
 * positive path is asserted here, first, and the negative-path suite only means
 * something once this one is green.
 */
function loginTenant(array $overrides = []): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, fn (): User => User::factory()->create(array_merge([
        'email' => 'agent@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ], $overrides)));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    return [$tenant, $user];
}

it('signs a staff user in with the correct password (FR-01)', function () {
    [, $user] = loginTenant();

    $response = $this->postJson('https://localhost/login', [
        'email' => 'agent@fixture.example',
        'password' => 'the-correct-password',
    ]);

    $response->assertOk()->assertJsonPath('status', 'signed_in');
    expect(auth('staff')->id())->toBe($user->getKey());
});

it('binds the tenant discovered from the account (Doc 01 FR-03a)', function () {
    [$tenant] = loginTenant();

    $this->postJson('https://localhost/login', [
        'email' => 'agent@fixture.example',
        'password' => 'the-correct-password',
    ])->assertOk();

    expect(app(TenantContext::class)->tenantId())->toBe($tenant->id);
});

it('refuses the wrong password', function () {
    loginTenant();

    $this->postJson('https://localhost/login', [
        'email' => 'agent@fixture.example',
        'password' => 'not-the-password',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'invalid_credentials');

    expect(auth('staff')->check())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Owner ruling R-2 (2026-08-01): the ambiguous-tenant refusal
|--------------------------------------------------------------------------
*/

it('refuses when one email AND password verify in two tenants, recording ambiguous_tenant', function () {
    // The collision: the same person, same password, in two workspaces. The
    // platform cannot know which was meant, and picking one would silently sign
    // them into the wrong operator's data.
    $shared = 'collision@fixture.example';
    $password = 'the-identical-password';

    $tenantA = createTenant();
    $userA = asTenant($tenantA, fn (): User => User::factory()->create([
        'email' => $shared, 'password' => Hash::make($password), 'password_set_at' => now(),
    ]));
    activateTenant($tenantA);

    $tenantB = createTenant();
    $userB = asTenant($tenantB, fn (): User => User::factory()->create([
        'email' => $shared, 'password' => Hash::make($password), 'password_set_at' => now(),
    ]));
    activateTenant($tenantB);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();

    $response = $this->postJson('https://localhost/login', [
        'email' => $shared,
        'password' => $password,
    ]);

    // Refused, and with the GENERIC message — FR-02/AC-01 are unchanged by the
    // ruling. A distinct message here would confirm the address exists in more
    // than one workspace, which is the inference AC-01 exists to prevent.
    $response->assertStatus(422)
        ->assertJsonPath('code', 'invalid_credentials')
        ->assertJsonPath('detail', __('auth.failed.generic'));

    expect(auth('staff')->check())->toBeFalse('SEV-1: an ambiguous login established a session');

    // …and the AUDIT trail names the real reason (the ruling's whole point:
    // the case was previously logged as bad_credentials and was therefore
    // invisible in the reports that would reveal it).
    foreach ([[$tenantA, $userA], [$tenantB, $userB]] as [$tenant, $user]) {
        $entries = asTenant($tenant, fn (): array => DB::table('audit_log')
            ->where('action_key', 'auth.login.failed')
            ->where('entity_id', $user->getKey())
            ->get()
            ->all());

        expect($entries)->toHaveCount(1, 'the ambiguous refusal was not audited for both candidates');

        $payload = json_decode((string) $entries[0]->payload, true);
        expect($payload['reason'])->toBe('ambiguous_tenant');
    }

    // The escape-hatch explanation lives in help/runbook copy only — it must
    // never appear in the response (owner ruling R-2).
    $body = mb_strtolower((string) $response->getContent());
    foreach (['reset', 'workspace', 'tenant', 'another account'] as $leak) {
        expect($body)->not->toContain($leak);
    }
});
