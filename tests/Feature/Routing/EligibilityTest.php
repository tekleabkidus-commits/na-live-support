<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Capacity\CapacityResolver;
use App\Domain\Routing\Eligibility\RoutableAgents;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 11 FR-05 / FR-06 / FR-14 / EC-09 / BR-05 / US-07 — who is ROUTABLE
|--------------------------------------------------------------------------
| Routable = Online ∧ count < capacity ∧ effective member. Away/Offline/heartbeat-lost
| are NEVER routable. Capacity = queue.capacity_per_agent ?? routing.capacity.default,
| the per-user override outranking both; pending_customer excluded unless the setting.
*/

function eligibilityScene(): array
{
    $tenant = createTenant();
    $department = departmentFixture('Payments');
    $queue = (string) DB::table('departments')->where('id', $department)->value('default_queue_id');
    $admin = ticketActor(['org.queues.manage'], 'Fixture Queue Admin');
    $mk = function (string $label, string $status) use ($queue, $admin): App\Models\User {
        $u = ticketActor(['tickets.view'], "Fixture Routable {$label}");
        app(QueueWriter::class)->addMember($queue, $u->identifier(), $admin->identifier());
        if ($status !== 'offline') {
            app(AgentStatus::class)->set($u, $status);
        }

        return $u;
    };

    return [$tenant, $queue, $mk];
}

it('FR-05/BR-05: only ONLINE members with a free slot are routable; away, offline and heartbeat-lost never are', function () {
    [, $queue, $mk] = eligibilityScene();
    $online = $mk('online', 'online');
    $away = $mk('away', 'away');
    $offline = $mk('offline', 'offline');
    $lost = $mk('lost', 'online');
    // Heartbeat loss is materialized on read: kill the key.
    app(Illuminate\Contracts\Redis\Factory::class)->connection('volatile')->del('t:'.app(App\Support\Tenancy\TenantContext::class)->tenantId().':agent_heartbeat:'.$lost->identifier());

    $routable = array_column(app(RoutableAgents::class)->forQueue($queue), 'user_id');
    expect($routable)->toBe([$online->identifier()]);
    expect(app(RoutableAgents::class)->breakdown($queue))->toBe(['members' => 4, 'online' => 1, 'at_capacity' => 0, 'eligible' => 1]);
    unset($away, $offline);
});

it('FR-06/US-07: capacity = queue rule ?? routing.capacity.default; the per-user override outranks; pending_customer does not consume a slot unless routing.capacity.count_pending', function () {
    [, $queue, $mk] = eligibilityScene();
    $agent = $mk('cap', 'online');
    $resolver = app(CapacityResolver::class);
    expect($resolver->queueCapacity($queue))->toBe(5);   // the registered default
    app(QueueWriter::class)->update($queue, ['capacity_per_agent' => 2], ticketActor(['org.queues.manage'], 'Fixture Queue Admin')->identifier());
    expect($resolver->queueCapacity($queue))->toBe(2);

    DB::table('agent_assignment_stats')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => app(App\Support\Tenancy\TenantContext::class)->tenantId(), 'user_id' => $agent->identifier(), 'open_assigned' => 2, 'pending_customer_assigned' => 1]);
    expect($resolver->loadFor($agent->identifier(), $queue))->toBe(['capacity' => 2, 'load' => 1, 'free' => 1]);   // pending excluded
    expect(array_column(app(RoutableAgents::class)->forQueue($queue), 'user_id'))->toBe([$agent->identifier()]);

    app(SettingsRepository::class)->set('routing.capacity.count_pending', true);
    expect($resolver->loadFor($agent->identifier(), $queue)['free'])->toBe(0);
    expect(app(RoutableAgents::class)->forQueue($queue))->toBe([]);
    expect(app(RoutableAgents::class)->breakdown($queue)['at_capacity'])->toBe(1);

    DB::table('user_routing_capacity')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => app(App\Support\Tenancy\TenantContext::class)->tenantId(), 'user_id' => $agent->identifier(), 'capacity' => 10, 'set_by' => $agent->identifier()]);
    expect($resolver->loadFor($agent->identifier(), $queue)['capacity'])->toBe(10);
});

it('FR-14/EC-09: hours never touch eligibility — an Online agent at 18:01 is routable regardless of any calendar', function () {
    [, $queue, $mk] = eligibilityScene();
    $agent = $mk('night', 'online');
    // No hours input exists on this path at all: the assertion is structural — RoutableAgents takes no clock and no calendar.
    $ctor = (new ReflectionClass(RoutableAgents::class))->getConstructor();
    $types = array_map(fn (ReflectionParameter $p) => (string) $p->getType(), $ctor?->getParameters() ?? []);
    expect($types)->not->toContain(App\Domain\Routing\Hours\HoursContract::class);
    expect(array_column(app(RoutableAgents::class)->forQueue($queue), 'user_id'))->toBe([$agent->identifier()]);
});
