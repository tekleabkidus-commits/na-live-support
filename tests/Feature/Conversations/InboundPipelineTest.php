<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Inbound\BatchTailHint;
use App\Domain\Conversations\Inbound\InboundDedupeStore;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Foundation\Retention\ColdSummaryStore;
use App\Http\Controllers\Channels\ChannelWebhookController;
use App\Jobs\Conversations\ConfirmBatchTail;
use App\Jobs\Conversations\NormalizeInbound;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Quota\Quota;
use App\Support\Quota\QuotaExceeded;
use App\Support\Quota\QuotaMeter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| Inbound pipeline (Doc 04 S20 step 3 — FR-10, FR-11, EC-01, EC-03, T-03,
| BR-05 consumption of Doc 01's frozen contract)
|--------------------------------------------------------------------------
|
| The controller tests mount ChannelWebhookController on a bare test route with
| the tenant ALREADY BOUND by the fixture — standing in for the Doc 02 binder
| middleware, whose own stack (binder → throttle → credential → state) is proven
| in the Doc 02 suites. Real channels mount this controller on that stack
| (Doc 05/13); no fake-channel production route exists, by design.
*/

function inboundFixture(): Tenant
{
    $tenant = createTenant();
    bindTenant($tenant);

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new FakeChannelAdapter(new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo', 'voice'],
            supportsTyping: true,
            supportsEdit: true,
            supportsDelete: true,
            supportsButtons: false,
            replyWindow: ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }

    return $tenant;
}

/** @return array<string, mixed> */
function inboundRaw(int $updateId, array $overrides = []): array
{
    return array_merge([
        'update_id' => $updateId,
        'chat_id' => 'chat-100',
        'message_id' => 'ext-m-'.$updateId,
        'from' => ['id' => 'user-1', 'display' => 'Fixture Customer', 'username' => '@fixture_inbound'],
        'kind' => 'text',
        'text' => 'hello from the fixture',
        'occurred_at' => '2026-08-01T10:00:00+00:00',
    ], $overrides);
}

function mountWebhookRoute(): void
{
    Route::post('test-webhooks/{channel}/{endpoint}', [ChannelWebhookController::class, 'ingest']);
}

function runInbound(array $raw): void
{
    $context = app(App\Support\Tenancy\TenantContext::class);
    $tenantId = $context->tenantId();

    // The job is constructed while the fixture tenant is bound (captureTenant
    // reads the ambient context, as a controller dispatch would)…
    Bus::dispatchSync(new NormalizeInbound('fakechan', 'bot-1', $raw));

    // …and BindTenantContextToJob CLEARS both layers when the job finishes —
    // worker discipline (R-01), but it also wipes the fixture's ambient binding,
    // so every assertion after this call would query blind. Re-bind.
    bindTenant($tenantId);
}

// ── FR-10 step 1: the edge ACKs and enqueues, NEVER processes ────────────────

test('FR-10 step 1: the webhook ACKs 202 and enqueues to the channel inbound family — nothing processed inline', function () {
    inboundFixture();
    mountWebhookRoute();
    Queue::fake();

    $response = $this->postJson('/test-webhooks/fakechan/bot-1', inboundRaw(1));

    $response->assertStatus(202);
    Queue::assertPushedOn('webchat-in', NormalizeInbound::class);

    // Never inline: no row, no customer, no outbox event exists after the ACK.
    expect(Message::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(DB::table('outbox')->where('name', 'message.received')->count())->toBe(0);
});

test('FR-10 step 1: an unregistered channel 404s indistinguishably (C-10) and enqueues nothing', function () {
    inboundFixture();
    mountWebhookRoute();
    Queue::fake();

    $this->postJson('/test-webhooks/nochan/bot-1', inboundRaw(2))->assertStatus(404);

    Queue::assertNothingPushed();
});

test('SEC-05 (Doc 01): the quota gate runs before the enqueue', function () {
    $tenant = createTenant(['quota_overrides' => ['quota.messages_per_minute' => 1]]);
    bindTenant($tenant);
    inboundFixtureAdapterOnly();
    mountWebhookRoute();
    Queue::fake();

    $bucketBefore = intdiv(time(), 60);
    $this->postJson('/test-webhooks/fakechan/bot-1', inboundRaw(3))->assertStatus(202);

    // The POST consumed the single token, so the meter refuses the next unit.
    $refused = null;
    try {
        app(QuotaMeter::class)->allow(Quota::MessagesPerMinute);
    } catch (QuotaExceeded $e) {
        $refused = $e;
    }

    if (intdiv(time(), 60) !== $bucketBefore) {
        // The minute bucket rolled between POST and assertion — the counter reset
        // legitimately and nothing can be asserted. (QuotaTest's own windowed tests
        // use the day bucket for exactly this reason; the controller wiring needs
        // the real minute quota.)
        $this->markTestSkipped('minute bucket rolled mid-test');
    }

    expect($refused)->toBeInstanceOf(QuotaExceeded::class);
});

function inboundFixtureAdapterOnly(): void
{
    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new FakeChannelAdapter(new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo', 'voice'],
            supportsTyping: true,
            supportsEdit: true,
            supportsDelete: true,
            supportsButtons: false,
            replyWindow: ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }
}

// ── FR-10 steps 2–4: normalize → dedupe → identity → persist+outbox ──────────

test('FR-10 end-to-end: one inbound becomes shell customer + conversation + message row + outbox events, atomically', function () {
    inboundFixture();
    Queue::fake([ConfirmBatchTail::class]); // fake ONLY the confirm job: a full
    // Queue::fake() intercepts even dispatchSync's push, silently running nothing

    runInbound(inboundRaw(10));

    $message = Message::query()->sole();
    $customer = Customer::query()->sole();

    expect($message->direction)->toBe('in')
        ->and($message->author_type)->toBe('customer')
        ->and($message->body)->toBe('hello from the fixture')
        ->and($message->quarantined)->toBeFalse()
        ->and($customer->is_shell)->toBeTrue()
        // S08 step 2 (A-40 wiring): the channel username lives on the typed
        // identity's metadata; customers.username is deprecated and unwritten
        // (SCHEMA AMENDMENT 2). Tests-only change, the SS-02 precedent.
        ->and(json_decode((string) DB::table('customer_identities')->where('customer_id', $customer->getKey())->value('metadata'), true)['username'] ?? null)->toBe('@fixture_inbound')
        // Step 4's one transaction left BOTH events on the outbox:
        ->and(DB::table('outbox')->where('name', 'conversation.created')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'message.received')->count())->toBe(1);
});

test('FR-01: the same sender again lands in the SAME conversation and customer; conversation.created stays at one', function () {
    inboundFixture();
    Queue::fake([ConfirmBatchTail::class]);

    runInbound(inboundRaw(20));
    runInbound(inboundRaw(21));

    expect(Message::query()->count())->toBe(2)
        ->and(Customer::query()->count())->toBe(1)
        ->and(Message::query()->distinct('conversation_id')->count('conversation_id'))->toBe(1)
        ->and(DB::table('outbox')->where('name', 'conversation.created')->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'message.received')->count())->toBe(2);
});

test('EC-03: a duplicate webhook delivery is dropped silently — one row, one event, metric only', function () {
    inboundFixture();
    Queue::fake([ConfirmBatchTail::class]);

    // Same update_id → same adapter dedupe_key, delivered twice (channel retry).
    runInbound(inboundRaw(30));
    runInbound(inboundRaw(30));

    expect(Message::query()->count())->toBe(1)
        ->and(DB::table('outbox')->where('name', 'message.received')->count())->toBe(1);
});

test('T-03: the dedupe slot admits its own claimant again (job retry) and refuses every other (real duplicate)', function () {
    inboundFixture();
    $dedupe = app(InboundDedupeStore::class);

    expect($dedupe->firstSight('fakechan', 'fake:77', 'job-A'))->toBeTrue()
        // The SAME job retrying after a mid-pipeline crash must pass its own mark —
        // dropping it would lose a never-persisted message (NFR-04).
        ->and($dedupe->firstSight('fakechan', 'fake:77', 'job-A'))->toBeTrue()
        // A channel redelivery is a DIFFERENT job: duplicate, dropped.
        ->and($dedupe->firstSight('fakechan', 'fake:77', 'job-B'))->toBeFalse();
});

// ── FR-11: blocked state quarantines — audit only, no fan-out, no retro ──────

test('FR-11: inbound on a blocked conversation is stored quarantined with audit as its ONLY trace; unblocking does not retro-fan-out', function () {
    inboundFixture();
    Queue::fake([ConfirmBatchTail::class]);

    // First contact establishes the conversation, then it gets blocked.
    runInbound(inboundRaw(40));
    $conversation = Message::query()->sole()->conversation_id;
    $repo = app(ConversationRepository::class);
    $model = App\Models\Conversation::query()->findOrFail($conversation);
    $repo->block($model);

    $eventsBefore = DB::table('outbox')->where('name', 'message.received')->count();

    runInbound(inboundRaw(41));

    $quarantined = Message::query()->where('quarantined', true)->sole();

    expect($quarantined->body)->not->toBeNull() // FR-03: still stored
        ->and(DB::table('outbox')->where('name', 'message.received')->count())->toBe($eventsBefore) // no fan-out
        ->and(DB::table('audit_log')->where('action_key', 'conversation.message_quarantined')->count())->toBe(1) // except audit
        ->and(Queue::pushed(ConfirmBatchTail::class)->filter(
            fn (ConfirmBatchTail $job): bool => $job->messageId === $quarantined->identifier(),
        ))->toHaveCount(0); // no batch-tail hint either — fan-out means ALL fan-out

    // Unblock, then prove nothing replays the quarantined row onto the bus.
    $repo->unblock($model);

    expect(DB::table('outbox')->where('name', 'message.received')->count())->toBe($eventsBefore)
        ->and($quarantined->refresh()->quarantined)->toBeTrue(); // the flag is Doc 06's to consume, not ours to clear
});

// ── BR-05: the frozen Doc 01 contract, consumed through the pipeline ─────────

test('BR-05: a suspended tenant past the accrual window gets ACK-but-summarize — counted, pointer kept, body NEVER stored', function () {
    $tenant = suspendTenant(activateTenant(createTenant()));

    // Push the suspension 40 days back (default window: 30) — the mode flips on
    // data, not on a mocked clock.
    // On the DEFAULT connection: the fixture tenant lives in the test's
    // uncommitted transaction, which no other connection can see.
    DB::table('tenants')->where('id', $tenant->tenantId())
        ->update(['suspended_at' => CarbonImmutable::now('UTC')->subDays(40)]);

    bindTenant($tenant);
    inboundFixtureAdapterOnly();
    Queue::fake([ConfirmBatchTail::class]);

    runInbound(inboundRaw(50));

    $summary = app(ColdSummaryStore::class)->summaryFor('fakechan');

    expect(Message::query()->count())->toBe(0)         // body never stored
        ->and(Customer::query()->count())->toBe(0)      // no shell either
        ->and($summary)->not->toBeNull()
        ->and($summary->message_count)->toBe(1)
        ->and($summary->last_message_pointer)->toBe('fakechan:chat-100:ext-m-50');
});

test('BR-05: a suspended tenant INSIDE the window still stores in full (US-02: reactivation loses nothing)', function () {
    $tenant = suspendTenant(activateTenant(createTenant()));
    bindTenant($tenant);
    inboundFixtureAdapterOnly();
    Queue::fake([ConfirmBatchTail::class]);

    runInbound(inboundRaw(60));

    expect(Message::query()->count())->toBe(1)
        ->and(app(ColdSummaryStore::class)->summaryFor('fakechan'))->toBeNull();
});

// ── EC-01: the batch-tail debounce ───────────────────────────────────────────

test('EC-01: a 10-message burst confirms exactly ONE batch tail — the last message', function () {
    inboundFixture();
    Queue::fake([ConfirmBatchTail::class]);

    for ($i = 0; $i < 10; $i++) {
        runInbound(inboundRaw(70 + $i));
    }

    $pushed = Queue::pushed(ConfirmBatchTail::class);
    expect($pushed)->toHaveCount(10);

    // Every confirm carries the 2 s debounce delay.
    foreach ($pushed as $job) {
        expect($job->delay)->toBe(2);
    }

    // Run the delayed confirms the queue would run 2 s later.
    $hint = app(BatchTailHint::class);
    $confirmed = [];
    foreach ($pushed as $job) {
        if ($hint->confirm($job->conversationId, $job->messageId)) {
            $confirmed[] = $job->messageId;
        }
    }

    $lastMessage = Message::query()->orderByDesc('id')->first();

    expect($confirmed)->toHaveCount(1)
        ->and($confirmed[0])->toBe($lastMessage?->identifier())
        ->and($hint->tailFor($lastMessage->conversation_id))->toBe($lastMessage->identifier());
});
