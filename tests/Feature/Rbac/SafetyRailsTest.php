<?php

declare(strict_types=1);

use App\Domain\Rbac\FourEyes;
use App\Domain\Rbac\RoleAdministration;
use App\Models\PendingGrant;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * AC-05 — the safety rails, every clause, through the REAL routes:
 * last-super-admin blocked (demotion path AND role-edit-strip path AND the
 * UserDisabler route), delete-assigned-role blocked (FR-08c/EC-01), and the
 * four-eyes flow requiring a DISTINCT second admin (FR-08b/T-06, ruling
 * R-9), with FS-04's stays-pending fail-safe and EC-08's deadlock guard.
 */
function railsFixture(): array
{
    $tenant = createTenant();

    [$admin, $second] = asTenant($tenant, function () {
        $admin = grantAllPermissions(User::factory()->create(['name' => 'Sara Bekele']));
        $second = User::factory()->create(['name' => 'Dawit Bekele']);
        $second->forceFill(['role_id' => $admin->getAttribute('role_id')])->save();

        return [$admin, $second];
    });
    activateTenant($tenant);

    return [$tenant, $admin, $second];
}

it('blocks demoting the LAST admin-pair holder, naming who else holds the rights (AC-05, FR-08a — demotion path)', function () {
    [$tenant, $admin, $second] = railsFixture();

    asTenant($tenant, function () use ($admin, $second): void {
        $agentRole = Role::create([
            'name' => 'Plain Agent', 'color' => 'role-agent',
            'permissions' => ['tickets.view'], 'scopes' => ['tickets' => 'Own'],
        ]);

        // Two holders: demoting ONE is fine — the error must not overfire.
        app(RoleAdministration::class)->assignRole($admin, $second, $agentRole);
        expect($second->fresh()->getAttribute('role_id'))->toBe($agentRole->getKey());
    });

    // Now the admin is the LAST holder: the route refuses, 403, naming state.
    $agentRoleId = asTenant($tenant, fn () => (string) Role::query()->where('name', 'Plain Agent')->value('id'));
    test()->actingAs($admin, 'staff')
        ->putJson('https://localhost/api/staff/admin/users/'.$admin->identifier().'/role', ['role_id' => $agentRoleId])
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden')
        ->assertJsonPath('other_holders', []);

    asTenant($tenant, function () use ($admin): void {
        expect(app(CapabilityMapProvider::class)->verdictFor($admin->fresh(), 'admin.roles.manage')->allowed)->toBeTrue();
    });
});

it('blocks a ROLE EDIT that would strip the pair from its last holders (AC-05, FR-08a — edit path)', function () {
    [$tenant, $admin] = railsFixture();

    $roleId = asTenant($tenant, fn () => (string) $admin->fresh()->getAttribute('role_id'));
    $version = asTenant($tenant, fn () => Role::query()->whereKey($roleId)->firstOrFail()->version);

    test()->actingAs($admin, 'staff')
        ->putJson('https://localhost/api/staff/admin/roles/'.$roleId, [
            'name' => 'Fixture Super Role',
            'color' => 'role-super-admin',
            'permissions' => ['tickets.view'], // the pair stripped
            'scopes' => [],
            'version' => $version,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');
});

it('blocks disabling the last admin THROUGH the UserDisabler route (AC-05, FR-08a — disable path)', function () {
    [$tenant, $admin, $second] = railsFixture();

    // Remove the second holder first (disable them — allowed while another
    // holder remains), then the LAST one must be refused.
    test()->actingAs($admin, 'staff')
        ->postJson('https://localhost/api/staff/admin/users/'.$second->identifier().'/disable')
        ->assertOk();

    test()->actingAs($admin, 'staff')
        ->postJson('https://localhost/api/staff/admin/users/'.$admin->identifier().'/disable')
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');

    asTenant($tenant, function () use ($admin): void {
        expect($admin->fresh()->isDisabled())->toBeFalse();
    });
});

it('blocks deleting an assigned role with the count, and deletes an unassigned one (AC-05, FR-08c, EC-01)', function () {
    [$tenant, $admin] = railsFixture();

    $roleId = asTenant($tenant, function (): string {
        $role = Role::create([
            'name' => 'Assigned Role', 'color' => 'role-agent',
            'permissions' => ['tickets.view'], 'scopes' => ['tickets' => 'Own'],
        ]);
        $member = User::factory()->create();
        $member->forceFill(['role_id' => $role->getKey()])->save();

        return $role->identifier();
    });

    test()->actingAs($admin, 'staff')
        ->deleteJson('https://localhost/api/staff/admin/roles/'.$roleId)
        ->assertStatus(409)
        ->assertJsonPath('assigned_count', 1);

    // EC-01: the mid-session-deletion aftermath is structurally impossible —
    // the role still exists.
    asTenant($tenant, function () use ($roleId): void {
        expect(Role::query()->whereKey($roleId)->exists())->toBeTrue();

        // Unassign, then deletion goes through (soft).
        User::query()->where('role_id', $roleId)->update(['role_id' => null]);
    });

    test()->actingAs($admin, 'staff')
        ->deleteJson('https://localhost/api/staff/admin/roles/'.$roleId)
        ->assertOk();

    asTenant($tenant, function () use ($roleId): void {
        expect(Role::query()->whereKey($roleId)->exists())->toBeFalse()
            ->and(Role::withTrashed()->whereKey($roleId)->exists())->toBeTrue()
            ->and(DB::table('outbox')->where('name', 'rbac.role.deleted')->count())->toBe(1);
    });
});

it('routes a high-risk grant through a DISTINCT second admin when four-eyes is on; the resolver ignores pending; requester cannot self-approve (AC-05, FR-08b, T-06, R-9)', function () {
    [$tenant, $admin, $second] = railsFixture();

    asTenant($tenant, function () use ($admin, $second): void {
        app(SettingsRepository::class)->set('rbac.four_eyes', true, AuditActor::System);

        $role = Role::create([
            'name' => 'Payments Specialist', 'color' => 'role-manager',
            'permissions' => ['tickets.view'], 'scopes' => ['tickets' => 'Dept'],
        ]);
        $role->refresh();
        $member = User::factory()->create();
        $member->forceFill(['role_id' => $role->getKey()])->save();

        // Granting customers.export (high) goes PENDING, not applied.
        $result = app(RoleAdministration::class)->update($admin, $role, [
            'name' => $role->name, 'description' => null, 'color' => $role->color,
            'permissions' => ['tickets.view', 'customers.export'],
            'scopes' => $role->scopes,
        ], $role->version, 'Finance needs the monthly reconciliation.');

        expect($result['pending'])->toBe(['customers.export'])
            ->and($role->fresh()->permissions)->toBe(['tickets.view'])
            // FS-04's fail-safe, live: the pending grant confers NOTHING.
            ->and(app(App\Support\Rbac\CapabilityMapBuilder::class)->build($member)->allows('customers.export'))->toBeFalse();

        $pending = PendingGrant::query()->where('status', 'pending')->firstOrFail();

        // The requester cannot decide their own request.
        expect(fn () => app(FourEyes::class)->approve($pending, $admin))
            ->toThrow(RuntimeException::class, 'cannot decide');

        // Nor can someone WITHOUT the R-9 approval right for the subject.
        $viewer = grantPermissions(User::factory()->create(), ['admin.roles.view'], 'Viewer Only Role');
        expect(fn () => app(FourEyes::class)->approve($pending, $viewer))
            ->toThrow(RuntimeException::class, 'admin.roles.manage');

        // A DISTINCT second admin applies it.
        app(FourEyes::class)->approve($pending, $second);

        expect($role->fresh()->permissions)->toContain('customers.export')
            ->and($pending->fresh()->status)->toBe('approved')
            ->and(app(App\Support\Rbac\CapabilityMapBuilder::class)->build($member)->allows('customers.export'))->toBeTrue()
            ->and(DB::table('outbox')->where('name', 'rbac.highrisk.granted')->count())->toBe(1)
            ->and(DB::table('outbox')->where('name', 'rbac.highrisk.confirmed')->count())->toBe(1);
    });
});

it('REFUSES to enable four-eyes with fewer than two approval-rights holders (EC-08 — the deadlock guard)', function () {
    $tenant = createTenant();
    asTenant($tenant, function (): void {
        // Exactly ONE approval-rights holder exists.
        grantAllPermissions(User::factory()->create());

        expect(fn () => app(SettingsRepository::class)->set('rbac.four_eyes', true, AuditActor::System))
            ->toThrow(RuntimeException::class, 'fewer than two');

        // With a second holder the same write succeeds (positive control).
        $second = User::factory()->create();
        $second->forceFill(['role_id' => Role::query()->where('name', 'Fixture Super Role')->value('id')])->save();

        app(SettingsRepository::class)->set('rbac.four_eyes', true, AuditActor::System);
        expect((bool) app(SettingsRepository::class)->get('rbac.four_eyes'))->toBeTrue();
    });
});
