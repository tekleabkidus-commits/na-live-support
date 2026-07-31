<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
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
