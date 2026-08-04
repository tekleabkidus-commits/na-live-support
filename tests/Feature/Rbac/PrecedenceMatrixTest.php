<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Rbac\CapabilityMapBuilder;
use App\Support\Rbac\VerdictSource;
use Illuminate\Support\Facades\DB;

/**
 * AC-02 — the normative precedence matrix (Doc 03 FR-04/FR-05):
 * User Deny > User Allow > Role > implicit deny, across
 * {role grant ∈ yes,no} × {override ∈ none,allow,deny} × {expired ∈ n/a,yes} —
 * all 10 combinations, each cell named. This suite is the living regression
 * net Doc 03 §21 hands QA; the resolver is implemented as an explicit
 * decision sequence and this file is what falsifies it.
 *
 * Expiry truth is the DB's clock (FR-09/EC-09): fixtures position expires_at
 * relative to Postgres' now(), never the app clock.
 *
 * Sabotage record (S19 step 3, pasted in the session log): role hoisted above
 * the override rungs → 2 cells red; expiry filter removed → 4 EXPIRED cells
 * red. The deny-vs-allow pair itself cannot be mis-ordered observably: one
 * live override per (user, permission) is a partial unique index
 * (RbacStorageTest), so no user ever carries both effects for one key.
 */
const MATRIX_KEY = 'customers.merge';

function matrixUser(bool $roleGrant, ?string $override, bool $expired): array
{
    $tenant = createTenant();

    return [$tenant, asTenant($tenant, function () use ($roleGrant, $override, $expired) {
        $admin = User::factory()->create();
        $role = Role::create([
            'name' => 'Matrix fixture role',
            'color' => 'accent-1',
            'permissions' => $roleGrant ? [MATRIX_KEY, 'tickets.view'] : ['tickets.view'],
            'scopes' => ['customers' => 'All', 'tickets' => 'All'],
        ]);
        $user = User::factory()->create();
        $user->forceFill(['role_id' => $role->getKey()])->save();

        if ($override !== null) {
            UserPermissionOverride::create([
                'user_id' => $user->identifier(),
                'permission' => MATRIX_KEY,
                'effect' => $override,
                'expires_at' => $expired
                    ? DB::selectOne("SELECT now() - interval '1 second' AS t")->t
                    : DB::selectOne("SELECT now() + interval '1 hour' AS t")->t,
                'granted_by' => $admin->identifier(),
                'reason' => 'Precedence matrix fixture override.',
            ]);
        }

        return $user;
    })];
}

/**
 * The 10 normative cells. Layout:
 * [role grant, override, expired, expected allowed, expected source]
 */
dataset('precedence-matrix', [
    'grant=yes override=none' => [true, null, false, true, VerdictSource::RoleGrant],
    'grant=no  override=none' => [false, null, false, false, VerdictSource::ImplicitDeny],
    'grant=yes override=allow live' => [true, 'allow', false, true, VerdictSource::OverrideAllow],
    'grant=no  override=allow live' => [false, 'allow', false, true, VerdictSource::OverrideAllow],
    'grant=yes override=allow EXPIRED → falls to role' => [true, 'allow', true, true, VerdictSource::RoleGrant],
    'grant=no  override=allow EXPIRED → implicit deny' => [false, 'allow', true, false, VerdictSource::ImplicitDeny],
    'grant=yes override=deny live → deny beats role' => [true, 'deny', false, false, VerdictSource::OverrideDeny],
    'grant=no  override=deny live' => [false, 'deny', false, false, VerdictSource::OverrideDeny],
    'grant=yes override=deny EXPIRED → role restores' => [true, 'deny', true, true, VerdictSource::RoleGrant],
    'grant=no  override=deny EXPIRED' => [false, 'deny', true, false, VerdictSource::ImplicitDeny],
]);

it('produces the normative verdict for every precedence cell (AC-02)', function (bool $grant, ?string $override, bool $expired, bool $allowed, VerdictSource $source) {
    [$tenant, $user] = matrixUser($grant, $override, $expired);

    asTenant($tenant, function () use ($user, $allowed, $source): void {
        $map = app(CapabilityMapBuilder::class)->build($user);
        $verdict = $map->verdict(MATRIX_KEY);

        expect($verdict->allowed)->toBe($allowed)
            ->and($verdict->source)->toBe($source);
    });
})->with('precedence-matrix');

it('denies EVERYTHING for a user with no role — implicit deny is the floor (FR-05, BR-01 null case)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $user = User::factory()->create();

        $map = app(CapabilityMapBuilder::class)->build($user);

        expect($map->allowedKeys())->toBe([])
            ->and($map->verdict('tickets.view')->allowed)->toBeFalse()
            ->and($map->verdict('tickets.view')->source)->toBe(VerdictSource::ImplicitDeny);
    });
});

it('never enforces an orphan DB key, and defaults a granted-nowhere catalog key to deny (T-01/FS-03)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $role = Role::create([
            'name' => 'Orphan fixture role',
            'color' => 'accent-1',
            'permissions' => ['tickets.view', 'tickets.teleport'],
            'scopes' => ['tickets' => 'Own'],
        ]);
        $user = User::factory()->create();
        $user->forceFill(['role_id' => $role->getKey()])->save();

        $map = app(CapabilityMapBuilder::class)->build($user);

        // The orphan key exists in the DB row; it must appear NOWHERE in the
        // map — not even as an explicit deny; it is not a permission at all.
        expect($map->has('tickets.teleport'))->toBeFalse()
            ->and($map->allowedKeys())->toBe(['tickets.view'])
            // A real catalog key granted nowhere: implicit deny.
            ->and($map->verdict('tickets.merge')->allowed)->toBeFalse();
    });
});

it('carries the granter and expiry on override verdicts — the explorer\'s WHY (§8, T-02)', function () {
    [$tenant, $user] = matrixUser(false, 'allow', false);

    asTenant($tenant, function () use ($user): void {
        $verdict = app(CapabilityMapBuilder::class)->build($user)->verdict(MATRIX_KEY);

        expect($verdict->grantedBy)->not->toBeNull()
            ->and($verdict->expiresAt)->not->toBeNull();
    });
});

it('lets an override change the VERB only — the scope map stays the role\'s, and the reserved scope column is dead (FR-04, §19)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $admin = User::factory()->create();
        $role = Role::create([
            'name' => 'Scope fixture role',
            'color' => 'accent-1',
            'permissions' => ['tickets.view'],
            'scopes' => ['tickets' => 'Own', 'customers' => 'Team'],
        ]);
        $user = User::factory()->create();
        $user->forceFill(['role_id' => $role->getKey()])->save();

        $override = UserPermissionOverride::create([
            'user_id' => $user->identifier(),
            'permission' => 'customers.merge',
            'effect' => 'allow',
            'granted_by' => $admin->identifier(),
            'reason' => 'Attempting to smuggle a scope widening.',
        ]);
        // §19: the reserved column holds a value; v1 must ignore it entirely.
        DB::table('user_permission_overrides')->where('id', $override->getKey())->update(['scope' => 'All']);

        $map = app(CapabilityMapBuilder::class)->build($user);

        expect($map->verdict('customers.merge')->allowed)->toBeTrue()
            // The scope for customers remains the ROLE's Team — the override
            // widened nothing (FR-04's documented limitation).
            ->and($map->scopeFor('customers'))->toBe('Team')
            ->and($map->scopeFor('tickets'))->toBe('Own');
    });
});

it('fails closed on a scoped module missing from the role\'s scope map — absent, never All (NFR-04 seam)', function () {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        $role = Role::create([
            'name' => 'Mapless fixture role',
            'color' => 'accent-1',
            // conversations.view is scoped but the scope map says nothing
            // about conversations.
            'permissions' => ['conversations.view'],
            'scopes' => [],
        ]);
        $user = User::factory()->create();
        $user->forceFill(['role_id' => $role->getKey()])->save();

        $map = app(CapabilityMapBuilder::class)->build($user);

        expect($map->scopeFor('conversations'))->toBeNull();
    });
});
