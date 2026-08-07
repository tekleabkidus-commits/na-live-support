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
use App\Models\Customer;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| S21 STEP 0 — FORCED-BACKSTEP POSITIVE CONTROL, in the soak's real geometry
|--------------------------------------------------------------------------
|
| WHAT WAS MISSING. ThreadOrderingKeyProbeTest proves a backward clock step
| inverts (created_at, id) in a FOUR-MESSAGE toy. It does not prove that the
| same mechanism produces run soak-10's shape: `body-order` violations, on a
| FULLY DRAINED 10 000-message run, across 50 competing lanes, with
| receipt-order clean. Waiting for that to recur by luck is not a plan — it
| happened once in six runs — so this manufactures it.
|
| WHAT IT DOES. It runs the soak's exact geometry (50 lanes x 200 = 10 000,
| interleaved composes, failures injected, full drain) while DRIVING THE CLOCK
| THAT WRITES created_at. Eloquent's freshTimestamp() is Date::now(), i.e.
| Carbon, so setTestNow is the real injection point — not the OS clock, which
| no test can move. Time advances 20 ms per compose, so one round of 50 lanes
| is ~1 simulated second and two messages of the SAME lane are ~1 s apart,
| which is the real geometry's spacing.
|
| At a chosen point the clock steps BACKWARD by more than that same-lane gap.
| The prediction is quantitative, and that is the point — "some violations"
| would prove very little:
|
|   TRANSIENT excursion (clock drops, N composes land low, clock restored):
|     exactly the N lanes composed inside the excursion invert. Nothing else,
|     because every other lane's neighbours are still ordered.
|
|   PERSISTENT step (clock drops and stays down):
|     EVERY lane inverts — the ones after the step in that round, then all the
|     rest on the following round, because the clock never catches back up.
|
| Matching the predicted COUNT, not merely producing violations, is what makes
| this evidence about the mechanism rather than a coincidence.
|
| INVERTED AT THE FIX — THIS IS NOW THE REGRESSION PROOF (R-19 / ADR 0010).
| The fork below was settled: the transient case reproduced soak-10 to the
| number (stampBacksteps=3, violations=3, body-order only, fully drained) and
| the persistent case inverted all 50 lanes. That established RISK 0c as
| SUFFICIENT in the real geometry, the owner ruled, and created_at moved to
| PostgreSQL's clock_timestamp().
|
| So both tests now assert the OPPOSITE, and that inversion is the proof the
| fix works. The excursion is still driven exactly as hard — the app clock
| still steps backwards by 2 s, transiently and permanently — but it no longer
| reaches the ordering key, so stampBacksteps and violations must BOTH be zero.
| If either test goes red, DB time has stopped being the source of created_at.
|
| The original fork, kept because it is what these numbers mean:
|   reproduces soak-10's shape  -> RISK 0c is SUFFICIENT in the real geometry
|   does NOT reproduce it       -> the clock is NOT the cause, a THIRD defect
|                                  exists, and no one should migrate the
|                                  ordering key until it is found
|
| WHY IT LIVES IN THE `soak` GROUP. It commits its fixtures, exactly as the
| soak does and for the same reason (10k rows across partitioned tables blow
| through max_locks_per_transaction inside one harness transaction). It must
| therefore run ALONE, which the `soak` group is what guarantees.
|
| STATUS: REGRESSION PROOF for ADR 0010. Deleting or relaxing either assertion
| is a gate-weakening change and requires the signed change-file process. If a
| future refactor makes them awkward, that is a signal about the refactor.
*/

/**
 * Run the soak's geometry with a scripted backward clock excursion.
 *
 * @param  int  $backstepMs  how far the clock drops
 * @param  int|null  $restoreAfter  composes to stay low before restoring;
 *                                  null = never restore (persistent step)
 * @return array{violations:int, kinds:array<string,int>, trueSent:int, total:int, nullExternalId:int, stampBacksteps:int, drainExit:string, affectedLanes:int}
 */
function runForcedBackstepSoak(int $backstepMs, ?int $restoreAfter, int $stepAtRound, int $stepAtLane): array
{
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
    $perLane = 200;
    $total = $lanes * $perLane;

    $customer = Customer::query()->create([
        'display_name' => 'Fixture', 'username' => '@fixture_backstep', 'is_shell' => true,
    ]);

    $conversations = [];
    for ($lane = 0; $lane < $lanes; $lane++) {
        $conversations[] = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'backstep-chat-'.$lane, $customer->identifier());
    }

    // Noon today: safely inside the current month's partition, so a couple of
    // seconds of backstep cannot fall outside it (the messages table is RANGE
    // partitioned on created_at and an insert with no partition would ERROR,
    // which would look like a defect and would not be one).
    $base = CarbonImmutable::now('UTC')->startOfDay()->addHours(12);

    $simMs = 0;
    $stepApplied = false;
    $composesSinceStep = 0;
    $restored = false;
    $affectedLanes = [];

    $stampBacksteps = 0;
    $laneLastStamp = [];
    $composedOrder = [];

    for ($round = 0; $round < $perLane; $round++) {
        foreach ($conversations as $lane => $conversation) {
            // Apply the excursion exactly at the chosen (round, lane).
            if (! $stepApplied && $round === $stepAtRound && $lane === $stepAtLane) {
                $simMs -= $backstepMs;
                $stepApplied = true;
            }

            if ($stepApplied && ! $restored && $restoreAfter !== null) {
                if ($composesSinceStep >= $restoreAfter) {
                    $simMs += $backstepMs;   // clock catches back up
                    $restored = true;
                } else {
                    $affectedLanes[$lane] = true;
                    $composesSinceStep++;
                }
            }

            $at = $base->addMilliseconds($simMs);
            Carbon::setTestNow($at);
            CarbonImmutable::setTestNow($at);

            $body = sprintf('lane-%d-seq-%03d', $lane, $round);
            $message = $compose->compose($conversation, 'agent', null, 'text', $body);
            bindTenant($tenantId);
            $composedOrder[$conversation->identifier()][] = $body;

            $stamp = $message->created_at?->format('Y-m-d H:i:s') ?? '';
            if (isset($laneLastStamp[$lane]) && $stamp < $laneLastStamp[$lane]) {
                $stampBacksteps++;
            }
            $laneLastStamp[$lane] = $stamp;

            $simMs += 20;   // ~1 simulated second per 50-lane round
        }
    }

    // The drain runs on the REAL clock; the excursion is a compose-phase event.
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();

    $now = CarbonImmutable::now('UTC');
    $cycle = 0;
    $sentCount = 0;
    $drainExit = 'loop-condition';
    $stallBudget = (int) ceil(LaneWorker::HEAD_BLOCK_CAP_SECONDS / 31) + 2;
    $consecutiveIdle = 0;

    while ($sentCount < $total) {
        $cycle++;

        if ($cycle % 37 === 0) {
            $adapter->scriptResult(SendResult::failed(new AdapterError(DeliveryFailureReason::NetworkError)));
        }

        $attempts = $worker->processOnce($now->addSeconds($cycle * 31));
        $adapter->sent = [];
        $sentCount = Message::query()->where('direction', 'out')->where('delivery_state', 'sent')->count();

        if ($attempts === 0) {
            if ($sentCount >= $total) {
                $drainExit = 'drained';

                break;
            }
            $consecutiveIdle++;
            if ($consecutiveIdle > $stallBudget) {
                $drainExit = 'STALLED';

                break;
            }
        } else {
            $consecutiveIdle = 0;
        }

        expect($cycle)->toBeLessThan($perLane * 4, 'the forced-backstep soak failed to drain');
    }

    $trueSent = Message::query()->where('direction', 'out')->where('delivery_state', 'sent')->count();
    $nullExternal = Message::query()->where('direction', 'out')->whereNull('external_message_id')->count();

    $violations = 0;
    $kinds = ['body-order' => 0, 'receipt-order' => 0];
    foreach ($conversations as $conversation) {
        $rows = Message::query()
            ->where('conversation_id', $conversation->identifier())
            ->where('direction', 'out')
            ->orderBy('created_at')->orderBy('id')
            ->selectRaw('body, external_message_id')
            ->get();

        $bodies = $rows->map(fn (Message $m): string => (string) $m->body)->all();
        if ($bodies !== $composedOrder[$conversation->identifier()]) {
            $violations++;
            $kinds['body-order']++;

            continue;
        }

        $previousReceipt = -1;
        foreach ($rows as $row) {
            $receipt = (int) str_replace('fake-out-', '', (string) $row->external_message_id);
            if ($receipt <= $previousReceipt) {
                $violations++;
                $kinds['receipt-order']++;

                break;
            }
            $previousReceipt = $receipt;
        }
    }

    return [
        'violations' => $violations,
        'kinds' => $kinds,
        'trueSent' => $trueSent,
        'total' => $total,
        'nullExternalId' => $nullExternal,
        'stampBacksteps' => $stampBacksteps,
        'drainExit' => $drainExit,
        'affectedLanes' => count($affectedLanes),
    ];
}

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

test('REGRESSION (transient): a 2 s excursion over 3 composes now inverts NOTHING — DB time ignores the app clock', function () {
    $tenant = activateTenant(createTenant());
    $tenantId = $tenant->tenantId();
    bindTenant($tenant);
    commitFixtures();

    try {
        // Step back 2 s (> the ~1 s same-lane gap) at round 100, starting at
        // lane 47, and restore after 3 composes: lanes 47, 48 and 49.
        $r = runForcedBackstepSoak(backstepMs: 2000, restoreAfter: 3, stepAtRound: 100, stepAtLane: 47);

        fwrite(STDERR, sprintf(
            "\n[BACKSTEP DIAG] mode=transient backstep=2000ms restoreAfter=3 affectedLanes=%d\n".
            "[BACKSTEP DIAG] drainExit=%s trueSent=%d total=%d nullExternalId=%d\n".
            "[BACKSTEP DIAG] stampBacksteps=%d\n".
            "[BACKSTEP DIAG] violations=%d kinds=%s\n\n",
            $r['affectedLanes'], $r['drainExit'], $r['trueSent'], $r['total'],
            $r['nullExternalId'], $r['stampBacksteps'], $r['violations'], json_encode($r['kinds']),
        ));

        // Fully drained — so this is an ORDER result, not a delivery artefact.
        expect($r['drainExit'])->not->toBe('STALLED')
            ->and($r['trueSent'])->toBe($r['total'])
            ->and($r['nullExternalId'])->toBe(0);

        // THE REGRESSION PROOF (R-19 / ADR 0010). Before the fix this excursion put
        // stampBacksteps=3 and violations=3 kinds={"body-order":3,...} on the board —
        // soak-10's exact shape. created_at now comes from PostgreSQL's
        // clock_timestamp(), so driving the APP clock backwards has no purchase on the
        // ordering key at all: the excursion still happens, and nothing inverts.
        expect($r['stampBacksteps'])->toBe(0, 'the app clock no longer reaches created_at; a backward step must leave the written stamps monotonic');

        expect($r['kinds']['body-order'])->toBe(0)
            ->and($r['kinds']['receipt-order'])->toBe(0)
            ->and($r['violations'])->toBe(0);
    } finally {
        cleanupTenants([$tenantId]);
    }
})->group('soak');

test('REGRESSION (persistent): a 2 s step that is never corrected still inverts NOTHING', function () {
    $tenant = activateTenant(createTenant());
    $tenantId = $tenant->tenantId();
    bindTenant($tenant);
    commitFixtures();

    try {
        // Same step, never restored: the clock stays behind, so every lane's
        // next message lands below its predecessor.
        $r = runForcedBackstepSoak(backstepMs: 2000, restoreAfter: null, stepAtRound: 100, stepAtLane: 47);

        fwrite(STDERR, sprintf(
            "\n[BACKSTEP DIAG] mode=persistent backstep=2000ms restoreAfter=none\n".
            "[BACKSTEP DIAG] drainExit=%s trueSent=%d total=%d nullExternalId=%d\n".
            "[BACKSTEP DIAG] stampBacksteps=%d\n".
            "[BACKSTEP DIAG] violations=%d kinds=%s\n\n",
            $r['drainExit'], $r['trueSent'], $r['total'],
            $r['nullExternalId'], $r['stampBacksteps'], $r['violations'], json_encode($r['kinds']),
        ));

        expect($r['drainExit'])->not->toBe('STALLED')
            ->and($r['trueSent'])->toBe($r['total'])
            ->and($r['nullExternalId'])->toBe(0);

        // Before the fix this was the worst case: stampBacksteps=50, every lane out of
        // order. The clock still steps and never recovers; the ordering key simply does
        // not listen to it any more.
        expect($r['stampBacksteps'])->toBe(0)
            ->and($r['kinds']['body-order'])->toBe(0)
            ->and($r['kinds']['receipt-order'])->toBe(0);
    } finally {
        cleanupTenants([$tenantId]);
    }
})->group('soak');
