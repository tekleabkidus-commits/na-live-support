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
use App\Domain\Conversations\Storage\MessageRepository;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| FR-15 — per-conversation FIFO lanes (Doc 04 S20 step 4; AC-03's head-block
| half — the 10k soak is the step-9 drill; §12's fairness rule)
|--------------------------------------------------------------------------
*/

function laneFixture(): array
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

    return [$adapter, app(ConversationRepository::class)];
}

function laneConversation(ConversationRepository $repo, string $chat): Conversation
{
    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_lane', 'is_shell' => true, 'created_source' => 'staff']);

    return $repo->findOrCreateForInbound('fakechan', 'bot-1', $chat, $customer->identifier());
}

function laneCompose(Conversation $conversation, string $body): Message
{
    // Sync test driver: the dispatch inside compose fills the lane inline and
    // its binding middleware clears the ambient context — re-bind (see
    // OutboundPipelineTest's helper for the full note).
    $tenantId = app(App\Support\Tenancy\TenantContext::class)->tenantId();

    $message = app(ComposeOutbound::class)->compose($conversation, 'agent', null, 'text', $body);

    bindTenant($tenantId);

    return $message;
}

test('FR-15: a lane sends in compose order, one attempt per cycle', function () {
    [$adapter, $repo] = laneFixture();
    $conversation = laneConversation($repo, 'chat-fifo');
    $now = CarbonImmutable::now('UTC');

    laneCompose($conversation, 'first');
    laneCompose($conversation, 'second');
    laneCompose($conversation, 'third');

    app(LaneWorker::class)->processOnce($now);
    app(LaneWorker::class)->processOnce($now);
    app(LaneWorker::class)->processOnce($now);

    $bodies = array_map(
        fn ($outbound): string => (string) $outbound->payload->body,
        $adapter->sent,
    );

    expect($bodies)->toBe(['first', 'second', 'third']);
});

test('FR-15: a failed(retryable) head BLOCKS the lane — the message behind it does not overtake', function () {
    [$adapter, $repo] = laneFixture();
    $conversation = laneConversation($repo, 'chat-block');
    $now = CarbonImmutable::now('UTC');

    $stuck = laneCompose($conversation, 'stuck');
    laneCompose($conversation, 'behind');

    $adapter->scriptResult(SendResult::failed(new AdapterError(DeliveryFailureReason::NetworkError)));
    app(LaneWorker::class)->processOnce($now);

    expect($stuck->refresh()->delivery_state)->toBe('failed_retryable');

    // Same instant (inside backoff): the lane is HELD; 'behind' is not attempted.
    app(LaneWorker::class)->processOnce($now);
    app(LaneWorker::class)->processOnce($now);

    expect(count($adapter->sent))->toBe(1) // only the failed attempt's record
        ->and(Message::query()->where('body', 'behind')->sole()->delivery_state)->toBe('queued');
});

test('FR-15: past the 10-minute cap the lane SKIPS — head demoted, conversation.lane_skipped + audit emitted, lane drains', function () {
    [$adapter, $repo] = laneFixture();
    $conversation = laneConversation($repo, 'chat-skip');
    $now = CarbonImmutable::now('UTC');

    $stuck = laneCompose($conversation, 'stuck');
    $behind = laneCompose($conversation, 'behind');

    // The head has been failing for 11 minutes and is mid-backoff (retry not due).
    app(MessageRepository::class)->updateDeliveryState($stuck, 'failed_retryable', [
        'reason' => 'network_error',
        'attempts' => 4,
        'first_failed_at' => $now->subMinutes(11)->toIso8601String(),
        'next_attempt_at' => $now->addMinutes(5)->toIso8601String(),
    ]);

    app(LaneWorker::class)->processOnce($now); // fires the skip

    expect(DB::table('outbox')->where('name', 'conversation.lane_skipped')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action_key', 'conversation.lane_skipped')->count())->toBe(1)
        ->and(app(OutboundLanes::class)->peekHead($conversation->identifier()))->toBe($behind->identifier());

    app(LaneWorker::class)->processOnce($now); // the lane now drains past the stuck head

    expect($behind->refresh()->delivery_state)->toBe('sent')
        ->and($stuck->refresh()->delivery_state)->toBe('failed_retryable'); // still retrying, from the tail
});

test('§12 fairness: a hot lane cannot starve idle lanes — one attempt per lane per cycle, every lane progresses', function () {
    [$adapter, $repo] = laneFixture();
    $now = CarbonImmutable::now('UTC');

    $hot = laneConversation($repo, 'chat-hot');
    for ($i = 0; $i < 5; $i++) {
        laneCompose($hot, 'hot-'.$i);
    }

    $quiet = [];
    foreach (['a', 'b', 'c'] as $suffix) {
        $conversation = laneConversation($repo, 'chat-quiet-'.$suffix);
        $quiet[] = laneCompose($conversation, 'quiet-'.$suffix);
    }

    // ONE cycle: four lanes, four sends — the hot lane got exactly one attempt.
    expect(app(LaneWorker::class)->processOnce($now))->toBe(4)
        ->and(count($adapter->sent))->toBe(4);

    foreach ($quiet as $message) {
        expect($message->refresh()->delivery_state)->toBe('sent');
    }

    $hotBodies = array_values(array_filter(
        array_map(fn ($outbound): ?string => $outbound->payload->body, $adapter->sent),
        fn (?string $body): bool => $body !== null && str_starts_with($body, 'hot-'),
    ));

    expect($hotBodies)->toBe(['hot-0']); // round-robin, not hot-lane monopoly
});
