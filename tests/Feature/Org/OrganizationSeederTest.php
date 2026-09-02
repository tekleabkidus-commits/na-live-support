<?php

declare(strict_types=1);

use App\Domain\Foundation\Provisioning\ProvisioningStep;
use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Support\Events\ConsumerRegistry;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-01 / BR-04 / EC-10 / FR-03 — the seeded organization (S11 STOP-C.1 packet)
|--------------------------------------------------------------------------
|
| Obligations bound at the countersign: the seeded set matches FR-01's named list
| EXACTLY; department + default queue in ONE transaction with default_queue_id set
| (FLAG A); the RG department carries the tenant's one is_rg queue; re-run = no-op and an
| edited seed survives (fingerprint contract); the step sits in ProvisioningStep::ordered()
| after DefaultRoles and before SlaPolicy; the ReopenConsumerDrillTest tenant.provisioned
| pin is UNCHANGED (a step, not a consumer). ConcurrentProvisioningTest proves the
| twice-concurrently → one set property through the provisioner itself.
*/

const FR01_DEPARTMENTS = ['General', 'Payments', 'Withdrawals', 'Deposits', 'Bonuses', 'Technical', 'Verification', 'Responsible Gaming', 'VIP'];

it('seeds EXACTLY FR-01\'s nine departments, in order, each with one default queue whose default_queue_id is set; Responsible Gaming holds the ONE is_rg queue; General is position 0', function () {
    $tenant = createTenant();
    bindTenant($tenant);

    expect(app(OrganizationSeeder::class)->seed())->toBe(9);

    $departments = DB::table('departments')->orderBy('position')->get();
    expect($departments)->toHaveCount(9);
    $names = $departments->map(fn ($d) => json_decode($d->name, true, 512, JSON_THROW_ON_ERROR)['en'])->all();
    expect($names)->toBe(FR01_DEPARTMENTS);
    expect($departments->first()->slug)->toBe('general')->and((int) $departments->first()->position)->toBe(0);

    foreach ($departments as $department) {
        expect($department->default_queue_id)->not->toBeNull("{$department->slug} was born defaultless (EC-10/FLAG A)");
        $queue = DB::table('queues')->where('id', $department->default_queue_id)->first();
        expect($queue)->not->toBeNull()->and($queue->department_id)->toBe($department->id);
        expect(json_decode($department->name, true, 512, JSON_THROW_ON_ERROR))->toHaveKeys(['en', 'am']);
        expect((bool) $department->seeded)->toBeTrue()->and($department->seeded_fingerprint)->not->toBeNull();
    }

    $rg = DB::table('queues')->where('is_rg', true)->get();
    expect($rg)->toHaveCount(1);
    expect(DB::table('departments')->where('id', $rg->first()->department_id)->value('slug'))->toBe('responsible-gaming');
});

it('is idempotent and never overwrites an operator-edited seed: a re-run creates nothing; a renamed department keeps its name', function () {
    $tenant = createTenant();
    bindTenant($tenant);
    app(OrganizationSeeder::class)->seed();

    DB::table('departments')->where('slug', 'payments')->update(['name' => json_encode(['en' => 'Money In', 'am' => 'ገንዘብ'])]);
    expect(app(OrganizationSeeder::class)->seed())->toBe(0);
    expect(DB::table('departments')->count())->toBe(9)
        ->and(DB::table('queues')->count())->toBe(9)
        ->and(json_decode(DB::table('departments')->where('slug', 'payments')->value('name'), true, 512, JSON_THROW_ON_ERROR)['en'])->toBe('Money In');
});

it('rides ProvisioningStep::Organization — ordered after DefaultRoles, before SlaPolicy, owned by Doc11, not a seam; tenant.provisioned consumers UNCHANGED', function () {
    $ordered = array_map(fn (ProvisioningStep $s) => $s->value, ProvisioningStep::ordered());
    expect($ordered)->toBe(['tenant_record', 'default_settings', 'default_roles', 'organization', 'sla_policy', 'business_hours', 'knowledge_base']);
    expect(ProvisioningStep::Organization->owningDoc())->toBe('Doc11')
        ->and(ProvisioningStep::Organization->isSeamAwaitingItsModule())->toBeFalse()
        // The disclosed rider: filled seams no longer report as awaiting.
        ->and(ProvisioningStep::DefaultRoles->isSeamAwaitingItsModule())->toBeFalse()
        ->and(ProvisioningStep::KnowledgeBase->isSeamAwaitingItsModule())->toBeFalse()
        ->and(ProvisioningStep::SlaPolicy->isSeamAwaitingItsModule())->toBeTrue();
    expect(__('foundation.provisioning.step.organization', [], 'en'))->not->toBe('foundation.provisioning.step.organization')
        ->and(__('foundation.provisioning.step.organization', [], 'am'))->not->toBe('foundation.provisioning.step.organization');
    // STOP-C.1 is a STEP, not a consumer: the drill pin's list is untouched.
    expect(ConsumerRegistry::consumersFor('tenant.provisioned'))->toBe([App\Jobs\Kb\KnowledgeBaseSeederConsumer::class, App\Jobs\Fields\CustomerFieldSeederConsumer::class]); // S12 batch D (P-FIELDS): the trio seeder joined, pin moved same commit
});

it('runs inside a real provisioning: a tenant provisioned through TenantProvisioner has its nine departments and is Active only after the organization step completed', function () {
    $tenantId = (string) Illuminate\Support\Str::uuid7();
    app(App\Support\Tenancy\TenantContext::class)->clear();
    app(App\Domain\Foundation\Provisioning\TenantProvisioner::class)->provision(['name' => 'Fixture Operator ORG'], $tenantId);

    asTenant($tenantId, function () use ($tenantId): void {
        expect(App\Models\Tenant::query()->findOrFail($tenantId)->state())->toBe(App\Domain\Foundation\Tenancy\TenantState::Active);
        expect(DB::table('departments')->count())->toBe(9)
            ->and(DB::table('queues')->where('is_rg', true)->count())->toBe(1)
            ->and(DB::table('tenant_provisioning_steps')->where('step', 'organization')->value('status'))->toBe('completed');
    });
});
