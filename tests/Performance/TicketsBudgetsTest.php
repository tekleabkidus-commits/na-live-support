<?php

declare(strict_types=1);

use App\Domain\Tickets\Bulk\BulkOperation;
use App\Domain\Tickets\Merge\MergeTickets;
use App\Domain\Tickets\Merge\UnmergeTicket;
use App\Domain\Tickets\Tags\TicketTags;
use App\Domain\Tickets\Timers\TicketTimers;
use App\Domain\Tickets\Timers\TicketTimerSweep;
use App\Domain\Tickets\Transitions\TicketStatus;
use App\Domain\Tickets\Transitions\TicketTransitionService;
use App\Domain\Tickets\Transitions\TransitionContext;
use App\Jobs\Tickets\TicketBulkJob;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 06 §12 / NFR-01 / NFR-02 / NFR-04 — the ticket budget suite (step 8)
|--------------------------------------------------------------------------
|
| DEV-HARDWARE-INDICATIVE, the Doc 01–04 pattern exactly: the default run asserts
| a 5× regression ceiling; PERF_BUDGETS_ENFORCE=1 (`make perf`) asserts the spec
| numbers themselves. Real numbers are printed on every run (reportBudget) — the
| honest record §12 demands, never faked.
|
| WHAT IS BUCKETED, PER THE PRE-AGREED §12 / Doc 19 SPLIT:
|  - "100k armed timers ≤ 5 s" runs here at in-suite scale (10k armed, 20 due) —
|    the full 100k profile is Doc 19 load work. The scan half is O(due), not
|    O(armed), by construction (ZRANGEBYSCORE returns due members only), which is
|    what the in-suite number demonstrates.
|  - "merge preview two 500-message timelines ≤ 400 ms" is Doc 07's READ surface —
|    no server preview endpoint exists yet; it lands with Doc 07's read models.
|  - "sustained 50 transitions/s platform-wide" is a fleet number (Doc 19 L2).
*/

/** A machine-walked resolved ticket in its own conversation (unique names — TimerPhysicsTest owns the sibling helper). */
function perfResolvedTicket(string $tenantId, string $customerId, string $label): Ticket
{
    $conversation = anotherConversation($tenantId, $label, $customerId);
    $ticket = machineClosedTicket($conversation, 'resolved');

    return $ticket;
}

test('NFR-01: a single transition commits within budget (p99 ≤ 25 ms)', function () {
    [, , $ticket] = ticketFixture('open');
    $service = app(TicketTransitionService::class);

    // Flip-flop between two legal states so every sample is a REAL committed
    // transition with its event, audit entry and trigger stamps.
    $flip = 0;
    $stats = measure(function () use ($service, $ticket, &$flip): void {
        $flip++;
        $service->transition(
            $ticket->fresh(),
            $flip % 2 === 1 ? TicketStatus::PendingCustomer : TicketStatus::Open,
            TransitionContext::system('perf sample'),
        );
    }, samples: 400);

    reportBudget('NFR-01 transition', 25.0, $stats);
    assertBudget('NFR-01 transition', $stats, 25.0);
});

test('NFR-02: a merge + unmerge ROUND completes within the single-operation budget (≤ 2 s)', function () {
    [$tenant, $conversation] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $customerId = $conversation->customer_id;
    $actor = ticketActor(['tickets.merge', 'tickets.view'], 'Fixture Perf Merge Role');

    $source = machineClosedTicket(anotherConversation($tenantId, 'perf-src', $customerId), 'open');
    $target = machineClosedTicket(anotherConversation($tenantId, 'perf-tgt', $customerId), 'open');

    // The round returns the world to its starting state, so one pair serves every
    // sample (snapshots are insert-only and accumulate — that is production truth,
    // not a fixture artifact). Hand-rolled timing: measure()'s fixed 50-call warmup
    // is sized for micro-operations, not for a two-transaction round.
    $timings = [];
    foreach (range(1, 28) as $i) {
        $start = hrtime(true);
        app(MergeTickets::class)->merge([$source->fresh()], $target->fresh(), $actor);
        app(UnmergeTicket::class)->unmerge($source->fresh(), $actor);
        $timings[] = (hrtime(true) - $start) / 1_000_000;
    }
    sort($timings);
    $stats = ['p50' => $timings[14], 'p99' => $timings[27], 'max' => $timings[27]];

    // Asserting the ROUND against the SINGLE-operation budget is deliberately
    // stricter than NFR-02 asks (each of merge and unmerge gets ≤ 2 s).
    reportBudget('NFR-02 merge+unmerge round', 2000.0, $stats);
    assertBudget('NFR-02 merge+unmerge round', $stats, 2000.0);
});

test('NFR-04: a 200-item bulk batch completes within budget (≤ 30 s), linear per item', function () {
    [$tenant, $conversation] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $customerId = $conversation->customer_id;
    $actor = ticketActor(['tickets.tags.manage', 'tickets.view'], 'Fixture Perf Bulk Role');

    Queue::fake();
    Event::fake([App\Events\Conversations\RealtimePush::class]);

    $runBatch = function (int $n, string $tag) use ($conversation, $tenantId, $customerId, $actor): float {
        $ids = [];
        foreach (range(1, $n) as $i) {
            $id = (string) Str::uuid7();
            DB::table('tickets')->insert([
                'id' => $id, 'tenant_id' => $tenantId,
                'conversation_id' => $conversation->identifier(), 'customer_id' => $customerId,
                'number' => random_int(1, 2_000_000_000), 'status' => 'closed',
                'priority' => 'normal', 'source' => 'agent',
            ]);
            $ids[] = $id;
        }
        $jobId = app(BulkOperation::class)->submit($actor, 'tag', $ids, ['tags' => [$tag]]);
        $start = hrtime(true);
        app()->call([new TicketBulkJob($jobId), 'handle']);

        return (hrtime(true) - $start) / 1_000_000;
    };

    $half = $runBatch(100, 'perf-half');
    $full = $runBatch(200, 'perf-full');

    $stats = ['p50' => $full, 'p99' => $full, 'max' => $full];
    reportBudget('NFR-04 bulk 200', 30_000.0, $stats);
    assertBudget('NFR-04 bulk 200', $stats, 30_000.0);

    // "linear per-item cost": 200 items must not cost more than ~3x the 100-item
    // batch — a loose superlinearity tripwire, not a microbenchmark.
    expect($full)->toBeLessThan($half * 3.0 + 500.0, sprintf(
        'NFR-04 linearity: 200 items took %.0f ms vs %.0f ms for 100 — superlinear per-item cost',
        $full, $half,
    ));
});

test('§12: the timer sweep is O(due), not O(armed) — 10k armed candidates, 20 due, one pass within the 5 s budget (in-suite scale)', function () {
    [$tenant, $conversation] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $customerId = $conversation->customer_id;
    Redis::connection('durable')->del(TicketTimers::ZSET);

    commitFixtures();

    try {
        // 10k ARMED-but-not-due candidates: raw future-scored members. The sweep's
        // ZRANGEBYSCORE never returns them — that is the O(due) claim itself.
        $farFuture = (int) (microtime(true) * 1_000_000) + 86_400_000_000;
        $batch = [];
        foreach (range(1, 10_000) as $i) {
            $batch[$tenantId.'|'.Str::uuid7()] = $farFuture + $i;
        }
        foreach (array_chunk($batch, 1000, true) as $chunk) {
            Redis::connection('durable')->zadd(TicketTimers::ZSET, $chunk);
        }

        // 20 genuinely due tickets, resolved through the machine and backdated.
        foreach (range(1, 20) as $i) {
            $due = perfResolvedTicket($tenantId, $customerId, 'perf-due-'.$i);
            DB::connection('pgsql_platform')->update(
                "UPDATE tickets SET resolved_at = resolved_at - interval '49 hours', auto_close_at = auto_close_at - interval '49 hours' WHERE id = ?",
                [$due->identifier()],
            );
            $due->refresh();
            app(TicketTimers::class)->add($tenantId.'|'.$due->identifier(), (int) $due->auto_close_at->format('Uu'));
        }

        $start = hrtime(true);
        $result = app(TicketTimerSweep::class)->sweep();
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        expect($result['closed'])->toBe(20, 'every due candidate must close in the one pass');

        $stats = ['p50' => $elapsed, 'p99' => $elapsed, 'max' => $elapsed];
        reportBudget('§12 sweep (10k armed/20 due)', 5000.0, $stats);
        assertBudget('§12 sweep (10k armed/20 due)', $stats, 5000.0);
    } finally {
        Redis::connection('durable')->del(TicketTimers::ZSET);
        cleanupTenants([$tenantId]);
    }
});

test('§12: tag autocomplete answers within budget at 5k distinct tags (p99 ≤ 80 ms)', function () {
    [$tenant, $conversation] = ticketFixture('open');
    $tenantId = $tenant->identifier();
    $customerId = $conversation->customer_id;

    // 250 closed rows x 20 distinct tags = 5 000 distinct tags, seeded in one
    // bulk insert (ids minted app-side — C-01's UUIDv7 holds even for fixtures;
    // Postgres 16 has no uuidv7()).
    $rows = [];
    foreach (range(1, 250) as $s) {
        $tags = [];
        foreach (range(1, 20) as $g) {
            $tags[] = sprintf('"perftag-%d-%d"', $s, $g);
        }
        $rows[] = [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->identifier(),
            'customer_id' => $customerId,
            'number' => 3_000_000 + $s,
            'status' => 'closed',
            'priority' => 'normal',
            'source' => 'agent',
            'tags' => '{'.implode(',', $tags).'}',
        ];
    }
    foreach (array_chunk($rows, 50) as $chunk) {
        DB::table('tickets')->insert($chunk);
    }

    /** @var object{n: int} $count */
    $count = DB::selectOne('SELECT count(DISTINCT tag) AS n FROM tickets, unnest(tags) AS tag WHERE tenant_id = ?', [$tenantId]);
    expect((int) $count->n)->toBeGreaterThanOrEqual(5000, 'the 5k-distinct-tag fixture collapsed');

    $tags = app(TicketTags::class);
    $stats = measure(fn () => $tags->suggest('perftag-1', 10), samples: 200);

    reportBudget('§12 tag autocomplete @5k', 80.0, $stats);
    assertBudget('§12 tag autocomplete @5k', $stats, 80.0);
});
