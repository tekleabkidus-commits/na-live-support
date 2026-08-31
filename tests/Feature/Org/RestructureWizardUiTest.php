<?php

declare(strict_types=1);

use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Org\Structure\QueueWriter;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-18 / §8 — the delete-wizard PAGES + the J17 deactivate door (S11 step 13;
| B5-S10 J16–J21). The pages carry identity only — inventory/choices/confirmation ride
| the step-10 JSON doors (ONE truth). J19's checks map arrives with the preview.
|--------------------------------------------------------------------------
*/

function wizardActor(array $permissions, string $roleName = 'S13 Wizard Admin'): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName], ['color' => 'role-agent', 'permissions' => $permissions, 'scopes' => ['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All']]);
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);

    return $user;
}

it('FR-18: the wizard pages render with target identity + move targets, gated by their manage keys; a tombstoned target 404s', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $admin = wizardActor(['org.departments.manage', 'org.teams.manage', 'org.queues.manage', 'tickets.view']);
    $blind = wizardActor(['tickets.view'], 'S13 Wizard Blind');

    activateTenant($tenant);
    $page = $this->actingAs($admin, 'staff')->get("https://localhost/admin/organization/queues/{$queue}/delete-wizard");
    $page->assertStatus(200);
    $props = $page->viewData('page')['props'];
    expect($page->viewData('page')['component'])->toBe('Admin/Org/RestructureWizard')
        ->and($props['kind'])->toBe('queue')
        ->and($props['target']['id'])->toBe($queue)
        ->and(collect($props['targets'])->pluck('id'))->not->toContain($queue, 'the target itself is never a move candidate');

    $dept = $this->actingAs($admin, 'staff')->get("https://localhost/admin/organization/departments/{$payments}/delete-wizard");
    $dept->assertStatus(200);
    $dprops = $dept->viewData('page')['props'];
    expect($dprops['kind'])->toBe('department');
    foreach ($dprops['targets'] as $candidate) {
        expect($candidate['department_id'])->not->toBe($payments, 'department delete: every candidate is OUTSIDE the department');
    }

    $this->actingAs($blind, 'staff')->get("https://localhost/admin/organization/queues/{$queue}/delete-wizard")->assertStatus(403);
    $this->actingAs($admin, 'staff')->get('https://localhost/admin/organization/queues/'.Illuminate\Support\Str::uuid7().'/delete-wizard')->assertStatus(404);
});

it('J19: the preview payload carries the per-system checks map — every section reported, all ok on a healthy tree', function () {
    $tenant = createTenant();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $admin = wizardActor(['org.departments.manage', 'org.queues.manage', 'tickets.view']);

    activateTenant($tenant);
    $preview = $this->actingAs($admin, 'staff')->getJson("https://localhost/org/queues/{$queue}/delete");
    $preview->assertStatus(200);
    $checks = $preview->json('checks');
    expect(array_keys($checks))->toBe(['rules', 'bots', 'sla_policies', 'views', 'tickets']);
    foreach ($checks as $section => $check) {
        expect($check['ok'])->toBeTrue("section {$section} should check ok on a healthy tree")
            ->and($check['reason'])->toBeNull();
    }
    // The department preview carries the same map.
    $dept = $this->actingAs($admin, 'staff')->getJson("https://localhost/org/departments/{$payments}/delete");
    $dept->assertStatus(200);
    expect(array_keys($dept->json('checks')))->toBe(['rules', 'bots', 'sla_policies', 'views', 'tickets']);
})->group('routing');

it('J17: the deactivate door stops intake through QueueWriter (audited, rules listed) and refuses the department default', function () {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    app(OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $default = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $admin = wizardActor(['org.queues.manage', 'tickets.view'], 'S13 Deactivate Admin');
    bindTenant($tenant);
    $other = app(QueueWriter::class)->create($payments, 'Deactivatable', $admin->identifier(), 'round_robin');

    activateTenant($tenant);
    $ok = $this->actingAs($admin, 'staff')->postJson("https://localhost/admin/organization/queues/{$other}/deactivate", []);
    $ok->assertStatus(200);
    expect($ok->json('ok'))->toBeTrue()->and($ok->json('rules_falling_to_default'))->toBeArray();
    bindTenant($tenant);
    expect((bool) DB::table('queues')->where('id', $other)->value('is_active'))->toBeFalse()
        ->and((int) DB::table('audit_log')->where('action_key', 'org.queue.deactivated')->where('entity_id', $other)->count())->toBe(1);

    activateTenant($tenant);
    $refused = $this->actingAs($admin, 'staff')->postJson("https://localhost/admin/organization/queues/{$default}/deactivate", []);
    $refused->assertStatus(422);
    expect($refused->json('refusal_code'))->not->toBeNull();
    bindTenant($tenant);
    expect((bool) DB::table('queues')->where('id', $default)->value('is_active'))->toBeTrue('EC-10: the department default never deactivates from this door');
});
