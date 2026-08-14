<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterError;
use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Adapters\SendResult;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Conversations\Outbound\LaneWorker;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| AC-03 — the 10k-message ordering soak (Doc 04 S20 step 9c)
|--------------------------------------------------------------------------
|
| AC-03: "FIFO: induced head failure holds the lane ≤ 10 min then lane-skip
| event; order violations = 0 across 10k-message soak."
|
| The head-failure/lane-skip half is proven in LaneFifoTest (step 4). THIS is
| the soak half, and it is a CORRECTNESS proof, not a perf number — it runs
| now, not in Doc 19 (the session prompt's own split).
|
| The soak interleaves composes across many conversations so lanes genuinely
| compete for the round-robin, injects retryable failures so heads block and
| recover mid-soak, and then asserts the only thing that matters: within each
| conversation, the adapter saw the messages in exactly the order they were
| composed. Zero violations means zero — not "few".
|
| WHY THIS ONE COMMITS ITS FIXTURES. Every other test runs inside a rolled-back
| transaction; at 10k messages across PARTITIONED tables that single transaction
| accumulates more relation locks than max_locks_per_transaction allows and dies
| with "out of shared memory" — a harness limit, not a product defect (observed
| on the first run of this soak). A soak is inherently a many-transactions
| scenario, so it commits like production does and cleans up after itself.
|
| WHY IT RUNS ALONE (`make soak`, group `soak`, excluded from `make test`).
| Running inside the shared suite, this test corrupts what follows it: the soak
| dies mid-body and every Performance test after it fails with spurious
| "relation ... does not exist" against a database whose tables demonstrably
| still exist afterwards. Measured, not guessed — full suite WITHOUT the soak:
| 648 passed, 0 failed; WITH it, on an otherwise idle machine: 7 failed. The
| mechanism is NOT root-caused (the committed transaction interacting with the
| harness's per-test transaction and the platform connection is the prime
| suspect) and is filed as an open defect in BUILD-STATUS.
|
| AND IT IS FLAKY IN ISOLATION TOO. Alone on a clean database this has passed
| twice (209 assertions, zero violations) and failed once with 50 violations —
| every lane — which smells like the drain loop exiting early and leaving
| messages unsent rather than a real ordering bug. Until that is fixed, AC-03's
| soak half is NOT claimed as proven; the head-failure/lane-skip half is, in
| LaneFifoTest. Do not treat a single green run of this file as evidence.
*/

test('AC-03: zero order violations across a 10k-message soak with failures injected mid-flight', function () {
    $tenant = activateTenant(createTenant());
    $tenantId = $tenant->tenantId();
    bindTenant($tenant);
    commitFixtures();

    try {

        $adapter = new FakeChannelAdapter(new Capabilities(
            maxText: 4096,
            mediaTypes: [],
            supportsTyping: false,
            supportsEdit: false,
            supportsDelete: false,
            supportsButtons: false,
            replyWindow: ReplyWindow::none(),
        ));

        $registry = app(AdapterRegistry::class);
        if (! in_array('fakechan', $registry->channels(), true)) {
            $registry->register('fakechan', $adapter, 'webchat-in', 'webchat-out');
        }

        $repo = app(ConversationRepository::class);
        $compose = app(ComposeOutbound::class);
        $worker = app(LaneWorker::class);
        $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();

        $lanes = 50;
        $perLane = 200;             // 50 × 200 = 10 000
        $total = $lanes * $perLane;

        // One customer, many conversations — lanes are per CONVERSATION (FR-15).
        $customer = Customer::query()->create([
            'display_name' => 'Fixture', 'username' => '@fixture_soak', 'is_shell' => true,
            'created_source' => 'staff',
        ]);

        /** @var list<Conversation> $conversations */
        $conversations = [];
        for ($lane = 0; $lane < $lanes; $lane++) {
            $conversations[] = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'soak-chat-'.$lane, $customer->identifier());
        }

        // INTERLEAVED composes: round after round across every lane, so the lanes
        // are genuinely concurrent work rather than 50 sequential drains.
        // CLOCK WATCH (S21 step 0, prompted by run soak-10; kept after R-19).
        //
        // HISTORY, because the counters only make sense with it. `created_at` used to
        // be written by the APP clock at whole-second precision, so a backward step
        // larger than the same-lane gap inverted the thread — proved by construction in
        // LaneOrderingForcedBackstepTest, which reproduced soak-10 exactly. Owner ruling
        // R-19 moved the column to PostgreSQL's clock_timestamp() and R-19a revoked the
        // app role's INSERT and UPDATE on it, so the app clock can no longer reach it.
        //
        // THREE measurements, and only the THIRD is authoritative.
        //
        // The two OS-clock counters are an ENVIRONMENT observation only: they are what
        // distinguishes this WSL2 host (Hyper-V TimeSync, hv_utils.timesync_implicit=1,
        // which really does step the guest clock) from a CI runner, and nothing more.
        // They never could speak for created_at: microtime() reads the OS clock while
        // Eloquent's freshTimestamp() returned Date::now(), i.e. CARBON, which honours
        // setTestNow. ThreadOrderingKeyProbeTest demonstrates them disagreeing outright.
        //
        // `stampBacksteps` is the real precondition: a same-lane regression of the
        // ACTUAL created_at value that reached the row, compared at MICROSECOND
        // precision. Post-R-19 it should be 0 on every run — and if it is ever not, DB
        // time has stopped being the source and the ordering guarantee is gone.
        $clockBacksteps = 0;
        $maxBackstepMs = 0.0;
        $laneBacksteps = 0;
        $maxLaneBackstepMs = 0.0;
        $laneLastWall = [];
        $prevWall = null;
        $wallStart = microtime(true);
        $monoStart = hrtime(true) / 1e9;

        // THE AUTHORITATIVE COUNTER: same-lane regression of the written created_at,
        // at full precision (see the '.u' format below).
        $stampBacksteps = 0;
        $laneLastStamp = [];

        /** @var array<string, list<string>> $composedOrder conversation id => bodies in compose order */
        $composedOrder = [];
        for ($round = 0; $round < $perLane; $round++) {
            foreach ($conversations as $lane => $conversation) {
                $body = sprintf('lane-%d-seq-%03d', $lane, $round);
                $message = $compose->compose($conversation, 'agent', null, 'text', $body);

                // Read the timestamp Eloquent actually wrote, truncated the way
                // the insert truncates it. This is the ordering key's leading
                // component, observed at the source rather than via a proxy clock.
                // MICROSECONDS, NOT SECONDS. Whole-second granularity mirrored the OLD
                // failure mode, when created_at could only differ by whole seconds. Since
                // R-19 the column carries microseconds, so a whole-second comparison
                // would read 0 unconditionally — a counter that can no longer fire, sat
                // in the diagnostics line looking like evidence. At '.u' it can still
                // catch the only regression left: a sub-second one.
                $stamp = $message->created_at?->format('Y-m-d H:i:s.u') ?? '';
                if (isset($laneLastStamp[$lane]) && $stamp < $laneLastStamp[$lane]) {
                    $stampBacksteps++;
                }
                $laneLastStamp[$lane] = $stamp;
                // The sync dispatch inside compose runs the job middleware, whose
                // finally clears both tenancy layers — re-bind or the next query
                // runs blind (the house pattern, see OutboundPipelineTest).
                bindTenant($tenantId);
                $composedOrder[$conversation->identifier()][] = $body;

                $wall = microtime(true);
                if ($prevWall !== null && $wall < $prevWall) {
                    $clockBacksteps++;
                    $maxBackstepMs = max($maxBackstepMs, ($prevWall - $wall) * 1000);
                }
                if (isset($laneLastWall[$lane]) && $wall < $laneLastWall[$lane]) {
                    $laneBacksteps++;
                    $maxLaneBackstepMs = max($maxLaneBackstepMs, ($laneLastWall[$lane] - $wall) * 1000);
                }
                $prevWall = $wall;
                $laneLastWall[$lane] = $wall;
            }
        }

        // Net divergence between the two clocks across the whole compose phase. A
        // TimeSync correction shows up here even when it was applied in slices too
        // small to register as a strictly-backward sample above.
        $clockSkewMs = ((microtime(true) - $wallStart) - ((hrtime(true) / 1e9) - $monoStart)) * 1000;

        expect(Message::query()->where('direction', 'out')->count())->toBe($total);

        // DRAIN with failures injected: every 37th attempt answers a retryable
        // error, so heads block, back off, and recover while the soak runs.
        $now = CarbonImmutable::now('UTC');
        $cycle = 0;
        $injected = 0;
        $sentCount = 0;
        $drainExit = 'loop-condition';   // DIAG (S21 step 0a)
        $breakCycle = 0;                 // DIAG

        // A zero-attempt cycle means "no lane was ELIGIBLE this cycle". It does
        // NOT mean "drained" — every head could be inside its backoff window. The
        // bound below is derived from the product's own constant rather than
        // chosen: a head may legally block its lane for HEAD_BLOCK_CAP_SECONDS
        // (600 s) before lane-skip, and each cycle advances 31 s, so ~20 cycles
        // is the longest legitimate all-quiet stretch. Past that, with messages
        // still unsent, the drain is genuinely STUCK and the soak says so.
        $stallBudget = (int) ceil(LaneWorker::HEAD_BLOCK_CAP_SECONDS / 31) + 2;
        $consecutiveIdle = 0;

        while ($sentCount < $total) {
            $cycle++;

            if ($cycle % 37 === 0) {
                $adapter->scriptResult(SendResult::failed(new AdapterError(DeliveryFailureReason::NetworkError)));
                $injected++;
            }

            // Time advances past the longest injected backoff so blocked heads
            // become eligible again — the lane still refuses to reorder around them.
            $attempts = $worker->processOnce($now->addSeconds($cycle * 31));

            // The fake adapter retains every OutboundMessage it is handed; at
            // 10k that alone exhausts the suite's memory limit (found when this
            // soak first ran inside the full run). Receipt order is read from
            // the recorded external ids below, so the objects are not needed.
            $adapter->sent = [];

            // Measured BEFORE the idle decision: "nothing eligible" and
            // "everything delivered" are different states and the old loop could
            // not tell them apart.
            $sentCount = Message::query()->where('direction', 'out')->where('delivery_state', 'sent')->count();

            if ($attempts === 0) {
                if ($sentCount >= $total) {
                    $drainExit = 'drained';   // DIAG — the only legitimate quiet exit
                    $breakCycle = $cycle;     // DIAG

                    break;
                }

                $consecutiveIdle++;

                if ($consecutiveIdle > $stallBudget) {
                    $drainExit = 'STALLED';   // DIAG
                    $breakCycle = $cycle;     // DIAG

                    break;
                }
            } else {
                $consecutiveIdle = 0;
            }

            expect($cycle)->toBeLessThan($perLane * 4, 'the soak failed to drain — investigate before trusting the order result');
        }

        // DIAGNOSTICS FIRST, AND BEFORE EVERY ASSERTION THAT CAN ABORT THE TEST.
        // Two reasons, both learned the hard way this session: (1) an assertion
        // that fires above this point would take the state dump with it, which is
        // the capture-first rule applied inside the test rather than around it;
        // (2) Pest TRUNCATES long assertion messages on screen ('at cycle 23…t
        // FIFO'), so the message alone cannot be the evidence channel — these
        // lines can, and they land in the captured file intact. Printed on green
        // runs too, so repeated runs are comparable rather than just "passed".
        $states = Message::query()->where('direction', 'out')
            ->selectRaw('delivery_state, count(*) as c')
            ->groupBy('delivery_state')->pluck('c', 'delivery_state')->all();
        $nullExternal = Message::query()->where('direction', 'out')->whereNull('external_message_id')->count();
        $trueSent = Message::query()->where('direction', 'out')->where('delivery_state', 'sent')->count();
        fwrite(STDERR, sprintf(
            "\n[SOAK DIAG] drainExit=%s breakCycle=%d cycles=%d injected=%d stallBudget=%d\n".
            "[SOAK DIAG] sentCount(var)=%d trueSent(db)=%d total=%d nullExternalId=%d\n".
            "[SOAK DIAG] deliveryStates=%s\n".
            "[SOAK DIAG] clockBacksteps=%d maxBackstepMs=%.3f clockSkewMs=%.3f\n".
            "[SOAK DIAG] laneBacksteps=%d maxLaneBackstepMs=%.3f  (OS clock, environment only)\n".
            "[SOAK DIAG] stampBacksteps=%d  <- THE body-order precondition (written created_at, microsecond)\n",
            $drainExit, $breakCycle, $cycle, $injected, $stallBudget,
            $sentCount, $trueSent, $total, $nullExternal,
            json_encode($states),
            $clockBacksteps, $maxBackstepMs, $clockSkewMs,
            $laneBacksteps, $maxLaneBackstepMs,
            $stampBacksteps,
        ));

        // COMPLETENESS BEFORE ORDER — the lesson of the S21 step-0 root cause.
        // An undelivered message carries a NULL external id, and the receipt walk
        // below parses NULL to 0, so two undelivered rows in a lane register as an
        // "order violation". That is how a drain that delivered NOTHING reported
        // itself as "50 order violations, every lane" and sent a whole session
        // hunting an ordering bug that was never there. Order is only a meaningful
        // question once delivery is established, so delivery is asserted FIRST and
        // a stall is named as a stall.
        $undelivered = Message::query()->where('direction', 'out')
            ->where(function ($q): void {
                $q->whereNull('external_message_id')->orWhere('delivery_state', '!=', 'sent');
            })->count();

        expect($drainExit)->not->toBe('STALLED', sprintf(
            'the drain STALLED at cycle %d with %d of %d messages still undelivered — this is a DELIVERY '
            .'failure, not an ordering failure; do not read the order result below as evidence about FIFO',
            $breakCycle, $total - $sentCount, $total,
        ));

        expect($undelivered)->toBe(0, sprintf(
            'the soak must DELIVER all %d messages before its ordering result means anything; %d were never '
            .'sent. Order violations counted over undelivered rows are an artefact of NULL external ids, '
            .'not evidence about FIFO',
            $total, $undelivered,
        ));

        // THE PROPERTY, read from recorded truth rather than test-double
        // bookkeeping: the fake adapter mints external ids from a monotonic
        // counter, so a message's external id IS the position at which the
        // adapter received it. Within a conversation, walking rows in FR-07
        // display order — (created_at, id), which is compose order — those
        // external ids must strictly increase. A later message overtaking a
        // blocked head would show up as a lower id after a higher one.
        $violations = 0;
        $violationKinds = ['body-order' => 0, 'receipt-order' => 0];   // DIAG
        $named = 0;   // DIAG — how many violating lanes have been dumped in full
        foreach ($conversations as $lane => $conversation) {
            $rows = Message::query()
                ->where('conversation_id', $conversation->identifier())
                ->where('direction', 'out')
                ->orderBy('created_at')->orderBy('id')
                ->selectRaw('body, id, external_message_id, created_at::text as ts')
                ->get();

            $bodies = $rows->map(fn (Message $m): string => (string) $m->body)->all();
            $expected = $composedOrder[$conversation->identifier()];
            if ($bodies !== $expected) {
                $violations++;   // the stored thread itself is out of compose order
                $violationKinds['body-order']++;   // DIAG

                // NAME THE DEFECT, do not just count it. Counting is what made the
                // historical red unreadable: "3 violations" sends the next session
                // hunting with no idea WHICH rows or WHY. The dump below prints the
                // first divergence with each row's stored created_at, so a clock
                // back-step (two rows whose ts ordering contradicts their compose
                // order) is visible on sight rather than inferred.
                if ($named < 3) {
                    $named++;
                    $at = 0;
                    while ($at < count($expected) && ($bodies[$at] ?? null) === $expected[$at]) {
                        $at++;
                    }

                    fwrite(STDERR, sprintf(
                        "[SOAK VIOLATION] lane=%d conversation=%s kind=body-order storedRows=%d expectedRows=%d firstDivergenceIndex=%d\n",
                        $lane, $conversation->identifier(), count($bodies), count($expected), $at,
                    ));

                    $from = max(0, $at - 2);
                    $to = min(count($bodies) - 1, $at + 2);
                    for ($i = $from; $i <= $to; $i++) {
                        $row = $rows[$i] ?? null;
                        fwrite(STDERR, sprintf(
                            "[SOAK VIOLATION]   slot %3d  ts=%s  stored=%-22s expected=%-22s ext=%s\n",
                            $i,
                            $row !== null ? (string) $row->ts : '-',
                            $bodies[$i] ?? '-',
                            $expected[$i] ?? '-',
                            $row !== null ? (string) $row->external_message_id : '-',
                        ));
                    }
                }

                continue;
            }

            $previousReceipt = -1;
            foreach ($rows as $row) {
                // Never let a NULL become a receipt of 0 — that silent coercion is
                // what disguised a delivery failure as an ordering one. Completeness
                // is asserted above, so this is belt-and-braces with a named message.
                expect($row->external_message_id)->not->toBeNull(
                    'an undelivered message has no receipt position; it cannot be evidence about ordering',
                );

                $receipt = (int) str_replace('fake-out-', '', (string) $row->external_message_id);

                if ($receipt <= $previousReceipt) {
                    $violations++;   // this message was sent BEFORE the one ahead of it
                    $violationKinds['receipt-order']++;   // DIAG

                    break;
                }

                $previousReceipt = $receipt;
            }
        }

        fwrite(STDERR, sprintf("[SOAK DIAG] violations=%d kinds=%s\n\n", $violations, json_encode($violationKinds)));   // DIAG

        expect($violations)->toBe(0)
            ->and($sentCount)->toBe($total)
            ->and($injected)->toBeGreaterThan(0, 'a soak that injected no failure proves less than it claims');
    } finally {
        // Committed fixtures clean themselves up, pass or fail.
        cleanupTenants([$tenantId]);
    }
})->group('soak');
