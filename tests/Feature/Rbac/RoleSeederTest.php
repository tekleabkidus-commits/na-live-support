<?php

declare(strict_types=1);

use App\Domain\Foundation\Provisioning\TenantProvisioner;
use App\Domain\Rbac\RoleSeeder;
use App\Domain\Rbac\RoleTemplates;
use App\Models\Role;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-10 + Appendix B (ruling R-10) + T-05(c)'s seeded-template drift test +
 * EC-07's never-overwrite-edited contract, which is PART OF the idempotency
 * contract, not a separate feature.
 */

/** BR-05's exact 18, pinned literally (ruling R-10) — the drift half of T-05(c). */
const READ_ONLY_KEYS = [
    'tickets.view', 'conversations.view', 'customers.view', 'customers.notes.view',
    'telegram.bots.view', 'telegram.health.view', 'webchat.widgets.view',
    'sms.templates.view', 'sms.gateways.view', 'ai.decisions.view', 'kb.feedback.view',
    'automation.rules.view', 'forms.view', 'sla.policies.view',
    'admin.users.view', 'admin.roles.view', 'reports.view', 'settings.view',
];

it('seeds the nine Appendix B templates through REAL provisioning, filling the declared seam (FR-10, Doc 01 FR-06)', function () {
    $tenantId = (string) Str::uuid7();
    app(TenantProvisioner::class)->provision(['name' => 'Fixture Seeder Operator'], $tenantId);

    asTenant($tenantId, function (): void {
        $roles = Role::query()->orderBy('created_at')->get()->keyBy('name');

        expect($roles)->toHaveCount(9)
            ->and($roles->keys()->sort()->values()->all())
            ->toBe(collect(RoleTemplates::NAMES)->sort()->values()->all());

        foreach ($roles as $role) {
            expect($role->is_system)->toBeTrue()
                ->and($role->seeded_fingerprint)->not->toBeNull();
        }

        // The provisioning step is recorded as genuinely completed.
        expect(DB::table('tenant_provisioning_steps')->where('step', 'default_roles')->whereNotNull('completed_at')->count())->toBe(1);
    });
});

it('pins the ratified grant constraints: SA=all keys (114 since S10 step 0.4, R-D10-1)/all-All, RO=the literal 18, zero high on agent tier + QA + RO, RG nowhere but SA (T-05c, BR-05, BR-06, R-10)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        app(RoleSeeder::class)->seed();
        $catalog = app(PermissionCatalog::class);
        $roles = Role::query()->get()->keyBy('name');

        // Super Admin: everything, all scopes All (FR-10's fixed clause).
        // 114 since S10 step 0.4: views.share + views.manage + ai.providers.manage
        // (the additions of record) ride SA by the all-keys clause; Admin gains
        // ai.providers.manage by its computed rule (everything minus the four); views.manage additionally
        // seeds the explicit templates per R-26.
        $sa = $roles[RoleTemplates::SUPER_ADMIN];
        expect($sa->permissions)->toHaveCount(114)
            ->and(array_diff(array_keys($catalog->all()), $sa->permissions))->toBe([])
            ->and($sa->scopes)->toEqual(['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All']);

        // Read Only: EXACTLY the 18 (kb.view_internal and audit.view stay out
        // by ruling R-10).
        $ro = $roles['Read Only'];
        expect(collect($ro->permissions)->sort()->values()->all())
            ->toBe(collect(READ_ONLY_KEYS)->sort()->values()->all());

        // Zero high-risk on the agent tier + QA + Read Only.
        $high = $catalog->highKeys();
        foreach (['Senior Agent', 'Agent', 'Junior Agent', 'QA', 'Read Only'] as $name) {
            expect(array_values(array_intersect($roles[$name]->permissions, $high)))
                ->toBe([], "{$name} carries a high-risk key");
        }

        // BR-06: RG keys in NO template but Super Admin.
        foreach ($roles as $name => $role) {
            if ($name === RoleTemplates::SUPER_ADMIN) {
                continue;
            }
            expect(array_values(array_intersect($role->permissions, ['customers.rg_flag.view', 'customers.rg_flag.set'])))
                ->toBe([], "{$name} carries an RG key");
        }

        // Every granted key exists in the catalog (no template can drift
        // ahead of the registry).
        foreach ($roles as $role) {
            foreach ($role->permissions as $key) {
                expect($catalog->has($key))->toBeTrue("unknown key {$key}");
            }
        }

        // Scope maps per the ratified table.
        expect($roles['Manager']->scopes['tickets'])->toBe('Dept')
            ->and($roles['Team Leader']->scopes['tickets'])->toBe('Dept')
            ->and($roles['Senior Agent']->scopes['customers'])->toBe('Team')
            ->and($roles['Agent']->scopes['tickets'])->toBe('Own')
            ->and($roles['QA']->scopes['conversations'])->toBe('All');
    });
});

it('is idempotent: provisioning-style double seed yields ONE consistent set (Doc 01 concurrency contract)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        expect(app(RoleSeeder::class)->seed())->toBe(9)
            ->and(app(RoleSeeder::class)->seed())->toBe(0)
            ->and(Role::query()->count())->toBe(9);
    });
});

it('NEVER overwrites an edited template, updates an untouched one on release change, and respects deletion (EC-07)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        app(RoleSeeder::class)->seed();

        // Operator edits Agent: adds a key. The edit survives every re-seed.
        $agent = Role::query()->where('name', 'Agent')->firstOrFail();
        $edited = [...$agent->permissions, 'tickets.tags.manage'];
        $agent->forceFill(['permissions' => $edited])->save();

        app(RoleSeeder::class)->seed();
        expect(Role::query()->where('name', 'Agent')->firstOrFail()->permissions)->toBe($edited);

        // An UNTOUCHED template whose release definition changed IS updated:
        // simulate an older release by rewinding QA's content+fingerprint to
        // a consistent-but-outdated pair.
        $qa = Role::query()->where('name', 'QA')->firstOrFail();
        $oldTemplate = [
            'description' => 'An older release description.',
            'color' => 'role-qa',
            'permissions' => ['tickets.view'],
            'scopes' => ['tickets' => 'All'],
        ];
        $qa->forceFill([
            'description' => $oldTemplate['description'],
            'permissions' => $oldTemplate['permissions'],
            'scopes' => $oldTemplate['scopes'],
            'seeded_fingerprint' => RoleSeeder::fingerprint('QA', $oldTemplate),
        ])->save();

        app(RoleSeeder::class)->seed();
        $qa->refresh();
        expect($qa->permissions)->toContain('reports.view')
            ->and($qa->description)->not->toBe('An older release description.');

        // THE DANGEROUS QUADRANT (surfaced by this step's sabotage run, which
        // stayed green until this case existed): operator-EDITED content AND
        // a CHANGED release. Without the edited-guard the release would
        // overwrite the operator's edit. Simulate: an old release fingerprint
        // plus content the operator then changed on top of it.
        $tl = Role::query()->where('name', 'Team Leader')->firstOrFail();
        $oldRelease = [
            'description' => 'An earlier Team Leader release.',
            'color' => 'role-team-leader',
            'permissions' => ['tickets.view'],
            'scopes' => ['tickets' => 'Dept'],
        ];
        $operatorEdited = ['tickets.view', 'customers.view'];
        $tl->forceFill([
            'description' => $oldRelease['description'],
            'permissions' => $operatorEdited,
            'scopes' => $oldRelease['scopes'],
            'seeded_fingerprint' => RoleSeeder::fingerprint('Team Leader', $oldRelease),
        ])->save();

        app(RoleSeeder::class)->seed();
        expect(Role::query()->where('name', 'Team Leader')->firstOrFail()->permissions)
            ->toBe($operatorEdited, 'EC-07 violated: a release overwrote an operator-edited template');

        // A DELETED template stays deleted — the seeder resurrects nothing.
        $ro = Role::query()->where('name', 'Read Only')->firstOrFail();
        $ro->delete();
        app(RoleSeeder::class)->seed();
        expect(Role::query()->where('name', 'Read Only')->exists())->toBeFalse()
            ->and(Role::withTrashed()->where('name', 'Read Only')->exists())->toBeTrue();
    });
});

it('emits rbac.role.created through the outbox and writes the audit entry per template (FR-12, T-07)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        app(RoleSeeder::class)->seed();

        expect(DB::table('outbox')->where('name', 'rbac.role.created')->count())->toBe(9)
            ->and(DB::table('audit_log')->where('action_key', 'rbac.role.created')->count())->toBe(9);

        $payload = json_decode((string) DB::table('outbox')->where('name', 'rbac.role.created')->first()->payload, true);
        expect(array_keys($payload))->toEqualCanonicalizing(['tenant_id', 'role_id', 'actor_id']);
    });
});
