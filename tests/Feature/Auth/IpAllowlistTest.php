<?php

declare(strict_types=1);

use App\Domain\Auth\Login\IpAllowlist;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Doc 02 SEC-05, closed at S19 step 7: per-ROLE IP allowlists, evaluated
 * post-auth pre-session; the refusal is FR-02's GENERIC error (nothing an
 * unauthenticated caller could not see), the truth is auth.ip.blocked (event
 * + audit) and auth.login.failed(reason=ip_blocked) —
 * FailureReason::IpBlocked, defined since S5, finally fires.
 */
function ipFixture(?array $allowlist, string $email = 'ip.fixture@fixture.example'): array
{
    $tenant = createTenant();
    $user = asTenant($tenant, function () use ($allowlist, $email) {
        $role = Role::create([
            'name' => 'Allowlisted Role',
            'color' => 'role-admin',
            'permissions' => ['settings.view'],
            'scopes' => [],
            'ip_allowlist' => $allowlist,
        ]);
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('the-correct-password'),
            'password_set_at' => now(),
        ]);
        $user->forceFill(['role_id' => $role->getKey()])->save();

        return $user;
    });
    activateTenant($tenant);

    app(App\Support\Tenancy\TenantContext::class)->clear();
    app(App\Support\Tenancy\RlsSession::class)->clear();
    test()->flushSession();

    return [$tenant, $user];
}

it('matches exact IPs and CIDR ranges, v4 and v6, and fails closed on malformed entries', function () {
    expect(IpAllowlist::permits(null, '196.188.1.7'))->toBeTrue()
        ->and(IpAllowlist::permits([], '196.188.1.7'))->toBeFalse()
        ->and(IpAllowlist::permits(['196.188.1.7'], '196.188.1.7'))->toBeTrue()
        ->and(IpAllowlist::permits(['196.188.1.7'], '196.188.1.8'))->toBeFalse()
        ->and(IpAllowlist::permits(['196.188.0.0/16'], '196.188.255.1'))->toBeTrue()
        ->and(IpAllowlist::permits(['196.188.0.0/16'], '196.189.0.1'))->toBeFalse()
        ->and(IpAllowlist::permits(['10.0.0.0/31'], '10.0.0.1'))->toBeTrue()
        ->and(IpAllowlist::permits(['10.0.0.0/31'], '10.0.0.2'))->toBeFalse()
        ->and(IpAllowlist::permits(['2a01:db8::/32'], '2a01:db8::1'))->toBeTrue()
        ->and(IpAllowlist::permits(['2a01:db8::/32'], '2a02:db8::1'))->toBeFalse()
        // Malformed entries never match; a v4 entry never matches a v6 address.
        ->and(IpAllowlist::permits(['not-an-ip', '196.188.0.0/notbits'], '196.188.1.7'))->toBeFalse()
        ->and(IpAllowlist::permits(['196.188.0.0/16'], '2a01:db8::1'))->toBeFalse();
});

it('refuses a CORRECT credential from an off-list IP with the GENERIC error, audits auth.ip.blocked, fires the event (SEC-05, FR-02)', function () {
    [$tenant, $user] = ipFixture(['198.51.100.0/24']);

    $response = test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('https://localhost/login', [
            'email' => 'ip.fixture@fixture.example',
            'password' => 'the-correct-password',
        ]);

    // FR-02: byte-for-byte the generic refusal — an allowlist violation must
    // not confirm the credential was correct.
    $response->assertStatus(422);
    expect(auth('staff')->check())->toBeFalse();

    asTenant($tenant, function () use ($user): void {
        expect(DB::table('audit_log')->where('action_key', 'auth.ip.blocked')->where('entity_id', $user->identifier())->count())->toBe(1)
            ->and(DB::table('audit_log')->where('action_key', 'auth.login.failed')->whereJsonContains('payload->reason', 'ip_blocked')->count())->toBe(1)
            ->and(DB::table('outbox')->where('name', 'auth.ip.blocked')->count())->toBe(1);

        $payload = json_decode((string) DB::table('outbox')->where('name', 'auth.ip.blocked')->first()->payload, true);
        expect(array_keys($payload))->toEqualCanonicalizing(['tenant_id', 'user_id', 'ip_hash']);
    });
});

it('admits the same credential from an on-list IP, and leaves NULL-allowlist roles unrestricted (SEC-05 positive controls)', function () {
    ipFixture(['198.51.100.0/24']);

    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
        ->postJson('https://localhost/login', [
            'email' => 'ip.fixture@fixture.example',
            'password' => 'the-correct-password',
        ])->assertOk();

    test()->flushSession();
    app('auth')->forgetGuards();

    ipFixture(null, 'ip.unrestricted@fixture.example');
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('https://localhost/login', [
            'email' => 'ip.unrestricted@fixture.example',
            'password' => 'the-correct-password',
        ])->assertOk();
});
