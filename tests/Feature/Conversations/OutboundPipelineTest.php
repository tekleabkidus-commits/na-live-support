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
use App\Domain\Conversations\Outbound\OutboundLanes;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Jobs\Conversations\EnqueueOutboundLane;
use App\Models\Conversation;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| Outbound pipeline (Doc 04 S20 step 4 — FR-12/13/14/16, T-03, EC-04, BR-02)
|--------------------------------------------------------------------------
|
| compose → persist(queued)+outbox → lane FIFO → rate gate → adapter.send →
| terminal state. `$now` is injected everywhere (§8 no-clock rule): backoff and
| refill instants are derived, never slept for. Under the sync test driver the
| EnqueueOutboundLane leg runs inline at compose's inner-transaction commit —
| see composeToLane() for the context-rebind consequence.
*/

function outboundFixture(): array
{
    $tenant = createTenant();
    bindTenant($tenant);

    $adapter = new FakeChannelAdapter(new Capabilities(
        maxText: 4096,
        mediaTypes: ['photo'],
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

    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_out', 'is_shell' => true]);
    $conversation = app(ConversationRepository::class)
        ->findOrCreateForInbound('fakechan', 'bot-1', 'chat-out-'.uniqid(), $customer->identifier());

    return [$conversation, $adapter];
}

/**
 * Compose under the sync test driver: EnqueueOutboundLane runs INLINE (SyncQueue
 * executes immediately; after_commit applies to real queue drivers), so the lane
 * is already filled — and the job's binding middleware cleared the ambient
 * context on its way out (worker discipline, R-01), so re-bind for assertions.
 */
function composeToLane(Conversation $conversation, ?string $body = 'outbound fixture', ?string $key = null): App\Models\Message
{
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();

    $message = app(ComposeOutbound::class)->compose($conversation, 'agent', null, 'text', $body, $key);

    bindTenant($tenantId);

    return $message;
}

function laneCycle(?CarbonImmutable $now = null): int
{
    return app(LaneWorker::class)->processOnce($now ?? CarbonImmutable::now('UTC'));
}

test('FR-12: compose writes the queued row + message.queued event and dispatches the lane push on the channel outbound family', function () {
    [$conversation] = outboundFixture();
    Queue::fake();

    $message = app(ComposeOutbound::class)->compose($conversation, 'agent', null, 'text', 'hello out');

    expect($message->delivery_state)->toBe('queued')
        ->and(DB::table('outbox')->where('name', 'message.queued')->count())->toBe(1);

    Queue::assertPushedOn('webchat-out', EnqueueOutboundLane::class);

    // The job's own half: run it, the lane holds exactly this message.
    $pushed = Queue::pushed(EnqueueOutboundLane::class)->first();
    app()->call([$pushed, 'handle']);

    expect(app(OutboundLanes::class)->peekHead($conversation->identifier()))->toBe($message->identifier());
});

test('FR-13 happy path: queued → sending → sent, external id recorded, lane drained', function () {
    [$conversation, $adapter] = outboundFixture();

    $message = composeToLane($conversation);

    expect(laneCycle())->toBe(1);

    expect($message->refresh()->delivery_state)->toBe('sent')
        ->and($message->external_message_id)->toBe('fake-out-1')
        ->and(DB::table('outbox')->where('name', 'message.sent')->count())->toBe(1)
        ->and(app(OutboundLanes::class)->peekHead($conversation->identifier()))->toBeNull()
        ->and($adapter->sent)->toHaveCount(1);
});

test('T-03: recomposing with the same idempotency key finds the ORIGINAL row — one row, one event, no sibling', function () {
    [$conversation] = outboundFixture();
    Queue::fake();

    $first = app(ComposeOutbound::class)->compose($conversation, 'agent', null, 'text', 'once', 'idem-key-1');
    $second = app(ComposeOutbound::class)->compose($conversation, 'agent', null, 'text', 'once', 'idem-key-1');

    expect($second->identifier())->toBe($first->identifier())
        ->and(DB::table('outbox')->where('name', 'message.queued')->count())->toBe(1);
});

test('BR-02: composing INTO a blocked conversation refuses with conversation_blocked — automations included', function () {
    [$conversation] = outboundFixture();
    app(ConversationRepository::class)->block($conversation);

    expect(fn () => app(ComposeOutbound::class)->compose($conversation, 'automation', null, 'text', 'x'))
        ->toThrow(DomainException::class, 'conversation_blocked');
});

test('EC-04: blocked BETWEEN compose and send → canceled with reason, pre-send only', function () {
    [$conversation, $adapter] = outboundFixture();

    $message = composeToLane($conversation);
    app(ConversationRepository::class)->block($conversation);

    laneCycle();

    expect($message->refresh()->delivery_state)->toBe('canceled')
        ->and(DB::table('outbox')->where('name', 'message.canceled')->count())->toBe(1)
        ->and($adapter->sent)->toHaveCount(0) // never reached the adapter
        ->and(app(OutboundLanes::class)->peekHead($conversation->identifier()))->toBeNull();
});

test('FR-13/FR-14 retryable path: backoff schedule stamps 1s then 5s; success on a later cycle sends', function () {
    [$conversation, $adapter] = outboundFixture();
    $now = CarbonImmutable::now('UTC');

    $message = composeToLane($conversation);

    $adapter->scriptResult(SendResult::failed(new AdapterError(DeliveryFailureReason::NetworkError)));
    laneCycle($now);

    $error = $message->refresh()->delivery_error;
    expect($message->delivery_state)->toBe('failed_retryable')
        ->and($error['attempts'])->toBe(1)
        ->and(CarbonImmutable::parse($error['next_attempt_at'])->getTimestamp())->toBe($now->addSecond()->getTimestamp());

    // Inside the backoff window the head blocks: nothing is attempted.
    expect(laneCycle($now))->toBe(0);

    // Past the window, attempt 2 fails again: the schedule's second step is 5 s.
    $adapter->scriptResult(SendResult::failed(new AdapterError(DeliveryFailureReason::ChannelUnavailable)));
    $atRetry = $now->addSeconds(2);
    laneCycle($atRetry);

    $error = $message->refresh()->delivery_error;
    expect($error['attempts'])->toBe(2)
        ->and(CarbonImmutable::parse($error['next_attempt_at'])->getTimestamp())->toBe($atRetry->addSeconds(5)->getTimestamp());

    // Attempt 3 succeeds — sent, lane drained, retry bookkeeping over.
    laneCycle($atRetry->addSeconds(6));
    expect($message->refresh()->delivery_state)->toBe('sent');
});

test('FR-13: the fifth failed attempt goes failed_final with message.failed carrying the reason', function () {
    [$conversation, $adapter] = outboundFixture();
    $now = CarbonImmutable::now('UTC');

    $message = composeToLane($conversation);

    foreach ([1, 5, 30, 120] as $i => $backoff) {
        $adapter->scriptResult(SendResult::failed(new AdapterError(DeliveryFailureReason::NetworkError)));
        laneCycle($now);
        $now = CarbonImmutable::parse($message->refresh()->delivery_error['next_attempt_at'])->addSecond();
        expect($message->delivery_error['attempts'])->toBe($i + 1);
    }

    $adapter->scriptResult(SendResult::failed(new AdapterError(DeliveryFailureReason::NetworkError)));
    laneCycle($now);

    expect($message->refresh()->delivery_state)->toBe('failed_final')
        ->and($message->delivery_error['attempts'])->toBe(5)
        ->and(DB::table('outbox')->where('name', 'message.failed')->value('payload'))->toContain('network_error')
        ->and(app(OutboundLanes::class)->peekHead($conversation->identifier()))->toBeNull();
});

test('FR-14: a non-retryable class goes failed_final IMMEDIATELY with its reason code', function () {
    [$conversation, $adapter] = outboundFixture();

    $message = composeToLane($conversation);
    $adapter->scriptResult(SendResult::failed(new AdapterError(DeliveryFailureReason::BlockedByUser)));

    laneCycle();

    expect($message->refresh()->delivery_state)->toBe('failed_final')
        ->and($message->delivery_error['reason'])->toBe('blocked_by_user')
        ->and($message->delivery_error['attempts'])->toBe(1);
});

test('FR-16: over-limit sends WAIT in queued and drain once the bucket refills — never dropped', function () {
    [$conversation, $adapter] = outboundFixture();
    $now = CarbonImmutable::now('UTC');

    $settings = app(App\Support\Settings\SettingsRepository::class);
    $settings->set('messaging.rate.outbound_per_second', 1, tenantSurface: false);
    $settings->set('messaging.rate.outbound_burst', 1, tenantSurface: false);

    $first = composeToLane($conversation, 'first');
    $second = composeToLane($conversation, 'second');

    // One token in the bucket: cycle 1 sends the head, cycle 2 (same instant) waits.
    laneCycle($now);
    laneCycle($now);

    expect($first->refresh()->delivery_state)->toBe('sent')
        ->and($second->refresh()->delivery_state)->toBe('queued') // waiting, not failed, not dropped
        ->and($adapter->sent)->toHaveCount(1);

    // A second later the bucket refilled: the waiter drains, in order.
    laneCycle($now->addSeconds(2));

    expect($second->refresh()->delivery_state)->toBe('sent')
        ->and($adapter->sent)->toHaveCount(2);
});

test('FR-13: manual retry re-enters queued from failed_final and sends on the next cycle', function () {
    [$conversation, $adapter] = outboundFixture();

    $message = composeToLane($conversation);
    $adapter->scriptResult(SendResult::failed(new AdapterError(DeliveryFailureReason::InvalidContent)));
    laneCycle();

    expect($message->refresh()->delivery_state)->toBe('failed_final');

    // The tickets.reply-gated route arrives with the compose surface; the EDGE
    // is the frozen contract and is exercised here directly.
    app(App\Domain\Conversations\Outbound\DeliveryStateMachine::class)
        ->transition($message, App\Domain\Conversations\Outbound\DeliveryState::Queued);
    app(OutboundLanes::class)->push($conversation->identifier(), $message->identifier());

    laneCycle();

    expect($message->refresh()->delivery_state)->toBe('sent');
});
