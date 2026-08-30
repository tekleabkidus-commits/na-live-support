<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Dispatch\QueueDispatcher;
use App\Domain\Routing\Pipeline\RoutingPipeline;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use Illuminate\Support\Facades\DB;

/*
| Doc 11 NFR-01 / NFR-02 / AC-04 / §12 — DEV-HARDWARE INDICATIVE figures (the RbacBudgetsTest
| shape): the routing decision (steps 1–4) and the availability reaction, measured, printed, and
| asserted only against a generous regression ceiling. The L2 p-values (200 eligible agents,
| 50 queues, 200-ticket bursts) are Doc 19's [D19]; nothing here claims them.
*/
it('meets NFR-01/NFR-02 indicatively: a routing decision and an availability reaction at dev scale (10 agents, 20 waiting) print their p50/p99 against the spec budgets', function () {
    createTenant();
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    $admin = ticketActor(['org.queues.manage'], 'Fixture Perf Admin');
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    for ($i = 0; $i < 10; $i++) {
        $a = ticketActor(['tickets.view'], "Fixture Perf Agent $i");
        app(QueueWriter::class)->addMember($queue, $a->identifier(), $admin->identifier());
    }
    $decision = [];
    for ($k = 0; $k < 20; $k++) {
        [, $conversation] = conversationFixture();
        $t = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $payments));
        $start = hrtime(true);
        app(RoutingPipeline::class)->route($t, 'created', ['explicit_department_id' => $payments]);   // nobody online → waiting
        $decision[] = (hrtime(true) - $start) / 1e6;
    }
    $agent = ticketActor(['tickets.view'], 'Fixture Perf Online');
    app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
    app(QueueWriter::class)->update($queue, ['capacity_per_agent' => 50], $admin->identifier());
    app(AgentStatus::class)->set($agent, 'online');
    $start = hrtime(true);
    $run = app(QueueDispatcher::class)->run($queue);
    $reaction = (hrtime(true) - $start) / 1e6;

    sort($decision);
    $p50 = $decision[(int) floor(count($decision) * 0.5)];
    $p99 = $decision[(int) floor(count($decision) * 0.99)];
    fwrite(STDERR, sprintf("\n  [dev-hardware-indicative] NFR-01 routing decision (steps 1–4)  p50 %6.1f ms  p99 %6.1f ms  budget(p99) 50 ms  %s\n", $p50, $p99, $p99 <= 50 ? 'WITHIN' : 'OVER'));
    fwrite(STDERR, sprintf("  [dev-hardware-indicative] NFR-02 availability reaction (%d assigned)  %6.1f ms  budget(p95) 2000 ms  %s\n", $run['assigned'], $reaction, $reaction <= 2000 ? 'WITHIN' : 'OVER'));
    expect($run['assigned'])->toBe(20);
    // 10× regression ceilings (the RbacBudgetsTest posture): a failure is a real order-of-magnitude regression or a loaded host — compare p50 to tell which.
    expect($p99)->toBeLessThanOrEqual(500.0, sprintf('routing decision p99 %.1f ms exceeds the 10x ceiling (p50 %.1f ms)', $p99, $p50));
    expect($reaction)->toBeLessThanOrEqual(20_000.0);
});
