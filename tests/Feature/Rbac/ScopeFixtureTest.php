<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\Scopes\MembershipSource;
use App\Support\Rbac\Scopes\ScopeColumns;
use App\Support\Rbac\Scopes\ScopeSets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * AC-03 — the scope fixture suite, Doc 03's LIVING CONTRACT for data scopes
 * (FR-03, T-03, NFR-04, EC-04, R-03).
 *
 * Membership is FIXTURE-LEVEL by the pre-agreed bucket: Doc 11 owns the real
 * Team/Department structures and must RE-PROVE its sources against this exact
 * suite when it lands — the traceability row records that; a bare DONE there
 * would be a false DONE.
 *
 * The scoped aggregate is synthetic (`rbac_scope_fixture_items`): Docs 04/06
 * own the real conversation/ticket tables. What this suite pins is the
 * CONSTRAINT CONTRACT — exact visible sets for Own/Team/Dept/All on list
 * queries and the object check, one implementation for both (R-03), empty-set
 * on every non-granted path. RLS is deliberately absent from the synthetic
 * table: Doc 01's isolation suite owns the backstop; this layer must fail
 * closed WITHOUT it (NFR-04's wording).
 */
final class ScopeFixtureItem extends Model
{
    protected $table = 'rbac_scope_fixture_items';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}

final class FixtureMembership implements MembershipSource
{
    /**
     * @param  array<string, list<string>>  $teams
     * @param  array<string, list<string>>  $departments
     */
    public function __construct(
        private array $teams = [],
        private array $departments = [],
    ) {}

    public function teamIdsFor(User $user): array
    {
        return $this->teams[$user->identifier()] ?? [];
    }

    public function departmentIdsFor(User $user): array
    {
        return $this->departments[$user->identifier()] ?? [];
    }
}

const SCOPE_COLUMNS = new ScopeColumns(owner: 'owner_id', team: 'team_id', department: 'department_id');

beforeEach(function () {
    $migrator = Schema::connection('pgsql_migrator');
    if (! $migrator->hasTable('rbac_scope_fixture_items')) {
        $migrator->create('rbac_scope_fixture_items', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('owner_id')->nullable();
            $table->uuid('team_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->text('label');
        });
    }
});

/**
 * One tenant, four scope levels, six items. Returns everything the
 * assertions need, with membership bound as a fixture.
 *
 * Teams:       T1, T2. Departments: D1, D2.
 * Users:       own (Own), team (member of T1), dept (member of D1),
 *              all (All), teamless (Team scope, NO team), mapless (scope map
 *              silent about the module).
 * Items:       i1 owned-by-own  T1 D1
 *              i2 other-owner   T1 D1
 *              i3 other-owner   T2 D1
 *              i4 other-owner   T2 D2
 *              i5 owned-by-own  T2 D2
 *              i6 other-owner   —  —   (no team, no department)
 */
function rbacScopeFixture(): array
{
    $tenant = createTenant();

    return asTenant($tenant, function () use ($tenant) {
        $teams = ['T1' => fake()->uuid(), 'T2' => fake()->uuid()];
        $departments = ['D1' => fake()->uuid(), 'D2' => fake()->uuid()];

        $mkRole = fn (string $name, array $scopes) => Role::create([
            'name' => $name,
            'color' => 'accent-1',
            'permissions' => ['tickets.view'],
            'scopes' => $scopes,
        ]);
        $roles = [
            'Own' => $mkRole('Scope Own', ['tickets' => 'Own']),
            'Team' => $mkRole('Scope Team', ['tickets' => 'Team']),
            'Dept' => $mkRole('Scope Dept', ['tickets' => 'Dept']),
            'All' => $mkRole('Scope All', ['tickets' => 'All']),
            'Silent' => $mkRole('Scope silent map', []),
        ];

        $mkUser = function (Role $role) {
            $user = User::factory()->create();
            $user->forceFill(['role_id' => $role->getKey()])->save();

            return $user;
        };
        $users = [
            'own' => $mkUser($roles['Own']),
            'team' => $mkUser($roles['Team']),
            'dept' => $mkUser($roles['Dept']),
            'all' => $mkUser($roles['All']),
            'teamless' => $mkUser($roles['Team']),
            'mapless' => $mkUser($roles['Silent']),
        ];

        app()->instance(MembershipSource::class, new FixtureMembership(
            teams: [$users['team']->identifier() => [$teams['T1']]],
            departments: [$users['dept']->identifier() => [$departments['D1']]],
        ));

        $other = User::factory()->create();
        $mkItem = fn (string $label, ?User $owner, ?string $team, ?string $dept) => ScopeFixtureItem::create([
            'id' => fake()->uuid(),
            'tenant_id' => $tenant->identifier(),
            'owner_id' => $owner?->identifier(),
            'team_id' => $team,
            'department_id' => $dept,
            'label' => $label,
        ]);
        $items = [
            'i1' => $mkItem('i1', $users['own'], $teams['T1'], $departments['D1']),
            'i2' => $mkItem('i2', $other, $teams['T1'], $departments['D1']),
            'i3' => $mkItem('i3', $other, $teams['T2'], $departments['D1']),
            'i4' => $mkItem('i4', $other, $teams['T2'], $departments['D2']),
            'i5' => $mkItem('i5', $users['own'], $teams['T2'], $departments['D2']),
            'i6' => $mkItem('i6', $other, null, null),
        ];

        return [$tenant, $users, $items];
    });
}

function visibleLabels(User $user): array
{
    $query = app(ScopeSets::class)->apply(ScopeFixtureItem::query(), 'tickets', $user, SCOPE_COLUMNS);

    return $query->orderBy('label')->pluck('label')->all();
}

it('produces the EXACT visible set for each scope level on list queries (AC-03, FR-03)', function () {
    [$tenant, $users] = rbacScopeFixture();

    asTenant($tenant, function () use ($users): void {
        // Own: assigned-to-me only, wherever the items sit.
        expect(visibleLabels($users['own']))->toBe(['i1', 'i5'])
            // Team: my team's items — INCLUDING one I don't own, EXCLUDING
            // my own item that sits in another team (scope is the dimension,
            // not ownership).
            ->and(visibleLabels($users['team']))->toBe(['i1', 'i2'])
            // Department: D1's items across teams.
            ->and(visibleLabels($users['dept']))->toBe(['i1', 'i2', 'i3'])
            // All: tenant-wide, including the orphan item.
            ->and(visibleLabels($users['all']))->toBe(['i1', 'i2', 'i3', 'i4', 'i5', 'i6']);
    });
});

it('answers the OBJECT check with the same implementation as the list filter (AC-03, R-03)', function () {
    [$tenant, $users, $items] = rbacScopeFixture();

    asTenant($tenant, function () use ($users, $items): void {
        $visible = fn (User $user, string $item) => app(ScopeSets::class)->objectVisible(
            ScopeFixtureItem::query(),
            $items[$item]->getKey(),
            'tickets',
            $user,
            SCOPE_COLUMNS,
        );

        expect($visible($users['own'], 'i1'))->toBeTrue()
            ->and($visible($users['own'], 'i2'))->toBeFalse()
            ->and($visible($users['team'], 'i2'))->toBeTrue()
            ->and($visible($users['team'], 'i3'))->toBeFalse()
            ->and($visible($users['dept'], 'i3'))->toBeTrue()
            ->and($visible($users['dept'], 'i4'))->toBeFalse()
            ->and($visible($users['all'], 'i6'))->toBeTrue();
    });
});

it('resolves Team scope with NO team to the empty set — fails closed, never open (EC-04)', function () {
    [$tenant, $users] = rbacScopeFixture();

    asTenant($tenant, function () use ($users): void {
        expect(visibleLabels($users['teamless']))->toBe([]);
    });
});

it('resolves a scope map SILENT about the module to the empty set (NFR-04 — missing filter fails closed)', function () {
    [$tenant, $users] = rbacScopeFixture();

    asTenant($tenant, function () use ($users): void {
        expect(visibleLabels($users['mapless']))->toBe([]);
    });
});

it('gives the production pre-Doc 11 binding EMPTY membership — Team/Dept scoped roles see nothing yet (T-03 seam)', function () {
    [$tenant, $users] = rbacScopeFixture();

    asTenant($tenant, function () use ($users): void {
        // Rebind the real default over the fixture: the shipped platform has
        // no membership sources until Doc 11.
        app()->instance(MembershipSource::class, new App\Support\Rbac\Scopes\EmptyMembership);

        expect(visibleLabels($users['team']))->toBe([])
            ->and(visibleLabels($users['dept']))->toBe([]);
    });
});
