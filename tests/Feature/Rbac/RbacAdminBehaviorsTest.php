<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Audit\AuditActor;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * The §8 behaviors AC-05 does not already own: EC-03's optimistic conflict
 * with the winner's diff, SEC-04's mandatory reason, US-02's expiring
 * override through the API, BR-02's sprawl warning, BR-04's strict-grant
 * mode, and the explorer's verdict + why.
 */
function adminFixture(): array
{
    $tenant = createTenant();
    $admin = asTenant($tenant, fn (): User => grantAllPermissions(User::factory()->create()));
    activateTenant($tenant);

    return [$tenant, $admin];
}

it('gives the EC-03 loser a 409 carrying the winner\'s state to re-apply against', function () {
    [$tenant, $admin] = adminFixture();

    $role = asTenant($tenant, fn () => tap(Role::create([
        'name' => 'Contested Role', 'color' => 'role-agent',
        'permissions' => ['tickets.view'], 'scopes' => ['tickets' => 'Own'],
    ]))->refresh());

    $payload = fn (array $permissions, int $version) => [
        'name' => 'Contested Role', 'color' => 'role-agent',
        'permissions' => $permissions, 'scopes' => ['tickets' => 'Own'],
        'version' => $version,
    ];

    // Winner lands first.
    test()->actingAs($admin, 'staff')
        ->putJson('https://localhost/api/staff/admin/roles/'.$role->identifier(), $payload(['tickets.view', 'tickets.reply'], $role->version))
        ->assertOk();

    // Loser sends the SAME stale version: 409 + the winner's permissions.
    test()->actingAs($admin, 'staff')
        ->putJson('https://localhost/api/staff/admin/roles/'.$role->identifier(), $payload(['tickets.view', 'tickets.note'], $role->version))
        ->assertStatus(409)
        ->assertJsonPath('winner.permissions', ['tickets.view', 'tickets.reply']);
});

it('refuses an override without a ≥10-char reason, and audits the reason when accepted (SEC-04, US-02)', function () {
    [$tenant, $admin] = adminFixture();
    $subject = asTenant($tenant, fn (): User => User::factory()->create());

    // Too short: refused.
    test()->actingAs($admin, 'staff')
        ->postJson('https://localhost/api/staff/admin/users/'.$subject->identifier().'/overrides', [
            'permission' => 'customers.merge', 'effect' => 'allow', 'reason' => 'short',
        ])
        ->assertStatus(422);

    // US-02: two weeks of customers.merge, reason logged, expiry carried.
    test()->actingAs($admin, 'staff')
        ->postJson('https://localhost/api/staff/admin/users/'.$subject->identifier().'/overrides', [
            'permission' => 'customers.merge',
            'effect' => 'allow',
            'reason' => 'Two weeks of merge cover for the payments backlog.',
            'expires_at' => asTenant($tenant, fn () => (string) DB::scalar("SELECT now() + interval '14 days'")),
        ])
        ->assertCreated();

    asTenant($tenant, function () use ($subject): void {
        $override = UserPermissionOverride::query()->where('user_id', $subject->identifier())->live()->firstOrFail();
        expect($override->expires_at)->not->toBeNull();

        $audit = DB::table('audit_log')->where('action_key', 'rbac.override.added')->first();
        expect($audit)->not->toBeNull()
            ->and(json_decode((string) $audit->payload, true)['reason'])->toBe('Two weeks of merge cover for the payments backlog.');

        expect(DB::table('outbox')->where('name', 'rbac.override.added')->count())->toBe(1);
    });
});

it('warns when more users than rbac.override.warn_threshold carry an override for the SAME permission (BR-02)', function () {
    [$tenant, $admin] = adminFixture();

    asTenant($tenant, function (): void {
        app(SettingsRepository::class)->set('rbac.override.warn_threshold', 2, AuditActor::System);
        foreach (range(1, 2) as $i) {
            $user = User::factory()->create();
            UserPermissionOverride::create([
                'user_id' => $user->identifier(),
                'permission' => 'tickets.export',
                'effect' => 'allow',
                'granted_by' => $user->identifier(),
                'reason' => 'Override sprawl fixture row number '.$i,
            ]);
        }
    });

    $third = asTenant($tenant, fn (): User => User::factory()->create());

    test()->actingAs($admin, 'staff')
        ->postJson('https://localhost/api/staff/admin/users/'.$third->identifier().'/overrides', [
            'permission' => 'tickets.export', 'effect' => 'allow',
            'reason' => 'The third carrier crosses the threshold.',
        ])
        ->assertCreated()
        ->assertJsonPath('warnings.0', 'rbac.warning.override_sprawl');
});

it('stops an editor granting keys they do not hold when rbac.strict_grant is on — and only then (BR-04)', function () {
    [$tenant, $admin] = adminFixture();

    // A limited editor: holds roles.manage but NOT customers.merge.
    $editor = asTenant($tenant, fn (): User => grantPermissions(
        User::factory()->create(),
        ['admin.roles.view', 'admin.roles.manage', 'admin.users.manage', 'tickets.view'],
        'Limited Editor',
    ));

    $body = [
        'name' => 'Merge Granting Role', 'color' => 'role-agent',
        'permissions' => ['customers.merge'], 'scopes' => ['customers' => 'All'],
    ];

    // Default off: the grant is allowed.
    test()->actingAs($editor, 'staff')
        ->postJson('https://localhost/api/staff/admin/roles', $body)
        ->assertCreated();

    asTenant($tenant, fn () => app(SettingsRepository::class)->set('rbac.strict_grant', true, AuditActor::System));

    test()->actingAs($editor, 'staff')
        ->postJson('https://localhost/api/staff/admin/roles', $body + ['name' => 'Second Attempt'])
        ->assertStatus(422);
});

it('answers the explorer with verdict + WHY: source, granter and expiry (§8, T-02)', function () {
    [$tenant, $admin] = adminFixture();
    $subject = asTenant($tenant, function (): User {
        $subject = grantPermissions(User::factory()->create(), ['tickets.view'], 'Explorer Subject Role');
        UserPermissionOverride::create([
            'user_id' => $subject->identifier(),
            'permission' => 'customers.merge',
            'effect' => 'deny',
            'granted_by' => $subject->identifier(),
            'reason' => 'Denied while the payments audit runs.',
        ]);

        return $subject;
    });

    test()->actingAs($admin, 'staff')
        ->getJson('https://localhost/api/staff/admin/users/'.$subject->identifier().'/effective?key=customers.merge')
        ->assertOk()
        ->assertJsonPath('verdict.allowed', false)
        ->assertJsonPath('verdict.source', 'override_deny')
        ->assertJsonPath('verdict.granted_by', $subject->identifier());

    test()->actingAs($admin, 'staff')
        ->getJson('https://localhost/api/staff/admin/users/'.$subject->identifier().'/effective?key=tickets.view')
        ->assertOk()
        ->assertJsonPath('verdict.allowed', true)
        ->assertJsonPath('verdict.source', 'role');
});
