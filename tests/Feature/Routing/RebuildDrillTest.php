<?php

declare(strict_types=1);

use App\Domain\Routing\Waiting\WaitingSets;
use App\Domain\Tickets\Creation\CreateTicket;
use App\Domain\Tickets\Creation\NewTicket;
use App\Domain\Tickets\Creation\TicketSource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/*
| Doc 11 FS-01 / R-03 — Redis routing state lost → rebuild from DB truth: waiting = open-family
| unassigned per queue; RR pointers fall back to the DB checkpoint; counters recomputed.
*/
it('FS-01: after the ZSETs and pointers are FLUSHED, routing:rebuild restores the waiting sets from tickets and the counters from truth; the pointer reads its DB checkpoint', function () {
    createTenant();
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    $payments = (string) DB::table('departments')->where('slug', 'payments')->value('id');
    $queue = (string) DB::table('departments')->where('id', $payments)->value('default_queue_id');
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        [, $conversation] = conversationFixture();
        $t = app(CreateTicket::class)->create(new NewTicket($conversation, TicketSource::Agent, departmentId: $payments));
        app(App\Domain\Routing\Pipeline\RoutingPipeline::class)->route($t, 'created', ['explicit_department_id' => $payments]);
        $ids[] = $t->identifier();
    }
    $sets = app(WaitingSets::class);
    expect($sets->depth($queue))->toBe(3);
    DB::table('queues')->where('id', $queue)->update(['rr_pointer_user_id' => '00000000-0000-7000-8000-0000000000ab']);

    // The loss.
    Redis::connection('durable')->del($sets->key($queue));
    Redis::connection('durable')->del(sprintf('t:%s:routing:rr:%s', app(App\Support\Tenancy\TenantContext::class)->tenantId(), $queue));
    expect($sets->depth($queue))->toBe(0);

    Artisan::call('routing:rebuild');
    expect($sets->depth($queue))->toBe(3)
        ->and($sets->head($queue, 3))->toBe($ids, 'FIFO order lost on rebuild')
        ->and(app(App\Domain\Routing\Strategies\RoundRobin::class)->pointer($queue))->toBe('00000000-0000-7000-8000-0000000000ab');
    expect(Artisan::output())->toContain('waiting member(s)');
});
