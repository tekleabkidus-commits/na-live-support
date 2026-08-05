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
        ]);

        /** @var list<Conversation> $conversations */
        $conversations = [];
        for ($lane = 0; $lane < $lanes; $lane++) {
            $conversations[] = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'soak-chat-'.$lane, $customer->identifier());
        }

        // INTERLEAVED composes: round after round across every lane, so the lanes
        // are genuinely concurrent work rather than 50 sequential drains.
        /** @var array<string, list<string>> $composedOrder conversation id → bodies in compose order */
        $composedOrder = [];
        for ($round = 0; $round < $perLane; $round++) {
            foreach ($conversations as $lane => $conversation) {
                $body = sprintf('lane-%d-seq-%03d', $lane, $round);
                $compose->compose($conversation, 'agent', null, 'text', $body);
                // The sync dispatch inside compose runs the job middleware, whose
                // finally clears both tenancy layers — re-bind or the next query
                // runs blind (the house pattern, see OutboundPipelineTest).
                bindTenant($tenantId);
                $composedOrder[$conversation->identifier()][] = $body;
            }
        }

        expect(Message::query()->where('direction', 'out')->count())->toBe($total);

        // DRAIN with failures injected: every 37th attempt answers a retryable
        // error, so heads block, back off, and recover while the soak runs.
        $now = CarbonImmutable::now('UTC');
        $cycle = 0;
        $injected = 0;
        $sentCount = 0;

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

            if ($attempts === 0) {
                break; // nothing left that is eligible: drained
            }

            $sentCount = Message::query()->where('direction', 'out')->where('delivery_state', 'sent')->count();

            expect($cycle)->toBeLessThan($perLane * 4, 'the soak failed to drain — investigate before trusting the order result');
        }

        // THE PROPERTY, read from recorded truth rather than test-double
        // bookkeeping: the fake adapter mints external ids from a monotonic
        // counter, so a message's external id IS the position at which the
        // adapter received it. Within a conversation, walking rows in FR-07
        // display order — (created_at, id), which is compose order — those
        // external ids must strictly increase. A later message overtaking a
        // blocked head would show up as a lower id after a higher one.
        $violations = 0;
        foreach ($conversations as $lane => $conversation) {
            $rows = Message::query()
                ->where('conversation_id', $conversation->identifier())
                ->where('direction', 'out')
                ->orderBy('created_at')->orderBy('id')
                ->get(['body', 'external_message_id']);

            $bodies = $rows->map(fn (Message $m): string => (string) $m->body)->all();
            if ($bodies !== $composedOrder[$conversation->identifier()]) {
                $violations++;   // the stored thread itself is out of compose order

                continue;
            }

            $previousReceipt = -1;
            foreach ($rows as $row) {
                $receipt = (int) str_replace('fake-out-', '', (string) $row->external_message_id);

                if ($receipt <= $previousReceipt) {
                    $violations++;   // this message was sent BEFORE the one ahead of it
                    break;
                }

                $previousReceipt = $receipt;
            }
        }

        expect($violations)->toBe(0)
            ->and($sentCount)->toBe($total)
            ->and($injected)->toBeGreaterThan(0, 'a soak that injected no failure proves less than it claims');
    } finally {
        // Committed fixtures clean themselves up, pass or fail.
        cleanupTenants([$tenantId]);
    }
})->group('soak');
