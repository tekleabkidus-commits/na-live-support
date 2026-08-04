<?php

declare(strict_types=1);

use App\Domain\Auth\Login\StaffLoginResolver;
use App\Models\User;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * FR-19 machine tokens: admin-created, service-account-bound, non-login
 * twice over, rotated with an overlap window (SS-04), audited as themselves
 * (BR-03). Step-9 ruling Q-B governs the find-or-create shape.
 */
function machineFixture(): array
{
    $tenant = createTenant();
    $admin = asTenant($tenant, fn (): User => grantAllPermissions(User::factory()->create([
        'email' => 'admin@fixture.example',
        'password' => Hash::make('the-correct-password'),
        'password_set_at' => now(),
    ])));
    // BR-07 (S19): a NEW service account requires a real role at creation.
    $serviceRole = asTenant($tenant, fn () => App\Models\Role::query()->firstOrCreate(
        ['name' => 'Fixture Service Role'],
        ['color' => 'role-agent', 'permissions' => ['settings.view', 'settings.edit', 'tickets.view'], 'scopes' => []],
    ));
    activateTenant($tenant);

    app(TenantContext::class)->clear();
    app(RlsSession::class)->clear();
    test()->flushSession();

    return [$tenant, $admin, $serviceRole];
}

function createMachineToken(User $admin, array $overrides = []): TestResponse
{
    // Guard named for the same reason as mintTokenViaApi (see that helper).
    return test()->actingAs($admin, 'staff')->postJson('https://localhost/api/staff/admin/machine-tokens', array_merge([
        'service_account' => 'Reporting Bot',
        'name' => 'reporting integration',
        'abilities' => ['settings.view'],
        'expiry' => '90',
        'role_id' => asTenant($admin->tenantId(), fn () => App\Models\Role::query()->where('name', 'Fixture Service Role')->value('id')),
    ], $overrides));
}

function machineBearer(string $plaintext, string $path = 'https://localhost/api/v1/token/context'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->getJson($path, ['Authorization' => 'Bearer '.$plaintext]);
}

it('creates a service-account-bound machine token that authenticates (FR-19 — the positive control)', function (): void {
    [$tenant, $admin] = machineFixture();

    $create = createMachineToken($admin)->assertCreated();

    expect($create->json('service_account'))->toBe('Reporting Bot');

    machineBearer($create->json('plaintext'))
        ->assertOk()
        ->assertJsonPath('resolved_tenant_id', $tenant->id);

    // The service account exists as a visible, self-identifying user row.
    $account = asTenant($tenant, fn (): ?User => User::query()
        ->where('email', 'reporting-bot@service-accounts.invalid')
        ->first());

    expect($account)->not->toBeNull()
        ->and($account->isServiceAccount())->toBeTrue()
        ->and($account->password_set_at)->toBeNull();

    // …and its creation was audited (ruling Q-B).
    $audit = asTenant($tenant, fn () => DB::table('audit_log')
        ->where('action_key', 'auth.service_account.created')
        ->where('entity_id', $account->identifier())
        ->first());

    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->identifier());
});

it('reuses an existing service account by name instead of minting a twin', function (): void {
    [$tenant, $admin] = machineFixture();

    createMachineToken($admin)->assertCreated();
    createMachineToken($admin, ['name' => 'second integration'])->assertCreated();

    $accounts = asTenant($tenant, fn (): int => User::query()
        ->where('email', 'reporting-bot@service-accounts.invalid')
        ->count());

    $createdAudits = asTenant($tenant, fn (): int => DB::table('audit_log')
        ->where('action_key', 'auth.service_account.created')
        ->count());

    expect($accounts)->toBe(1)
        ->and($createdAudits)->toBe(1, 'find-or-create must not audit a creation that did not happen');
});

it('never lets a service account log in — refused at the SQL resolver, with a HUMAN control (FR-19 non-login)', function (): void {
    [$tenant, $admin] = machineFixture();
    createMachineToken($admin)->assertCreated();

    test()->flushSession();
    app('auth')->forgetGuards();

    // POSITIVE CONTROL: the sibling HUMAN user in the same tenant logs in
    // fine — the refusal below is about service accounts, not a broken login.
    test()->postJson('https://localhost/login', [
        'email' => 'admin@fixture.example',
        'password' => 'the-correct-password',
    ])->assertOk();

    test()->flushSession();
    app('auth')->forgetGuards();

    // Layer 2, directly at the resolver: resolve_staff_login() returns ZERO
    // candidates for the service-account address — the login path never even
    // sees it, so the attempt costs the same two equalised dummy hashes as a
    // nonexistent address (AC-01 unaffected).
    expect(app(StaffLoginResolver::class)->candidatesFor('reporting-bot@service-accounts.invalid'))->toBe([])
        ->and(app(StaffLoginResolver::class)->candidatesFor('admin@fixture.example'))->toHaveCount(1);

    // And over HTTP: the generic refusal, byte-shaped like any bad credential.
    test()->postJson('https://localhost/login', [
        'email' => 'reporting-bot@service-accounts.invalid',
        'password' => 'the-correct-password',
    ])->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
});

it('rotates with an overlap window: both tokens live until the explicit revoke (FR-19, SS-04)', function (): void {
    [$tenant, $admin] = machineFixture();

    $old = createMachineToken($admin)->assertCreated();
    $oldId = $old->json('token.id');

    $rotated = test()->actingAs($admin, 'staff')
        ->postJson('https://localhost/api/staff/admin/machine-tokens/'.$oldId.'/rotate')
        ->assertCreated();

    expect($rotated->json('replaces'))->toBe($oldId)
        ->and($rotated->json('token.abilities'))->toBe(['settings.view']);

    // SS-04's zero-failed-calls property: DURING the overlap, both credentials
    // answer — no instant exists where the integration is locked out.
    machineBearer($old->json('plaintext'))->assertOk();
    machineBearer($rotated->json('plaintext'))->assertOk();

    // The rotation audit names its predecessor (FR-19 "all audited").
    $audit = asTenant($tenant, fn () => DB::table('audit_log')
        ->where('action_key', 'auth.token.created')
        ->where('entity_id', $rotated->json('token.id'))
        ->first());

    expect(json_decode((string) $audit->payload, true)['rotation_of'])->toBe($oldId)
        ->and(json_decode((string) $audit->payload, true)['kind'])->toBe('machine');

    // Closing the window is the explicit revoke — and it kills EXACTLY the old
    // credential.
    test()->actingAs($admin, 'staff')
        ->deleteJson('https://localhost/api/staff/admin/machine-tokens/'.$oldId)
        ->assertOk();

    machineBearer($old->json('plaintext'))->assertStatus(401)->assertJsonPath('code', 'invalid_token');
    machineBearer($rotated->json('plaintext'))->assertOk();
});

it('attributes a machine token\'s scope denial to its VISIBLE service account, never "the system" (BR-03)', function (): void {
    [$tenant, $admin] = machineFixture();

    // A permission-gated route on the real bearer stack (ruling Q-A shape).
    Route::middleware(['tenant.bearer', 'auth:api', 'tenant.state:outbound', 'permission:settings.edit'])
        ->put('api/v1/machine-suite/write', fn () => response()->json(['ok' => true]));

    $create = createMachineToken($admin)->assertCreated();

    app('auth')->forgetGuards();
    test()->putJson('https://localhost/api/v1/machine-suite/write', [], ['Authorization' => 'Bearer '.$create->json('plaintext')])
        ->assertStatus(403)
        ->assertJsonPath('missing_permission', 'settings.edit');

    $account = asTenant($tenant, fn (): ?User => User::query()
        ->where('email', 'reporting-bot@service-accounts.invalid')->first());

    $audit = asTenant($tenant, fn () => DB::table('audit_log')
        ->where('action_key', 'auth.token.scope_denied')
        ->first());

    expect($audit->actor_type)->toBe('staff', 'BR-03: never "system" for a machine token')
        ->and($audit->actor_id)->toBe($account->identifier(), 'the actor is the service account itself');
});

it('does not manage personal tokens through the machine surface — 404, not someone else\'s credential', function (): void {
    [, $admin] = machineFixture();

    // A PERSONAL token of the admin's own…
    $personal = test()->actingAs($admin, 'staff')->postJson('https://localhost/api/staff/security/tokens', [
        'name' => 'personal', 'abilities' => ['settings.view'], 'expiry' => '90',
    ])->assertCreated();

    // …is invisible to the machine endpoints, whose every query joins
    // "owner is a service account".
    test()->actingAs($admin, 'staff')
        ->postJson('https://localhost/api/staff/admin/machine-tokens/'.$personal->json('token.id').'/rotate')
        ->assertNotFound();

    test()->actingAs($admin, 'staff')
        ->deleteJson('https://localhost/api/staff/admin/machine-tokens/'.$personal->json('token.id'))
        ->assertNotFound();
});
