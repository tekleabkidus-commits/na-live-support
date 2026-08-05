<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Adapters\SupportsDelete;
use App\Domain\Conversations\Adapters\SupportsEdit;
use App\Domain\Conversations\Adapters\SupportsTyping;
use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Conversations\Outbound\LaneWorker;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Jobs\Conversations\NormalizeInbound;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Adapters\MinimalChannelAdapter;

/*
|--------------------------------------------------------------------------
| SS-02 — the architecture proof (Doc 04; ruling R-16; US-05)
|--------------------------------------------------------------------------
|
| "WhatsApp prototype adapter passes the conformance suite and the whole
| product works on it with zero core edits." Claimed per R-16 via this SECOND
| minimal fake adapter: a different payload shape, a different (SMS-like)
| capability envelope, ONLY the base contract implemented — registered and
| driven through the REAL product pipelines. The zero-core-edits half of the
| proof is the commit introducing the adapter: it touches tests/ only, and
| its diff is pasted in the session report (R-16's requirement). The
| traceability row carries R-16's verbatim caveat, never a bare DONE.
*/

function minimalFixture(): MinimalChannelAdapter
{
    $tenant = createTenant();
    bindTenant($tenant);

    $adapter = new MinimalChannelAdapter(new Capabilities(
        maxText: 320,
        mediaTypes: [],
        supportsTyping: false,
        supportsEdit: false,
        supportsDelete: false,
        supportsButtons: false,
        replyWindow: ReplyWindow::duration(86_400),
    ));

    $registry = app(AdapterRegistry::class);
    if (! in_array('minchan', $registry->channels(), true)) {
        // Existing S0 queue families — a new adapter never invents new ones.
        $registry->register('minchan', $adapter, 'webchat-in', 'webchat-out');
    }

    return $adapter;
}

test('SS-02: the second adapter implements ONLY the base contract — the minimal envelope is structural, not configured', function () {
    $adapter = minimalFixture();

    expect($adapter)->not->toBeInstanceOf(SupportsTyping::class)
        ->and($adapter)->not->toBeInstanceOf(SupportsEdit::class)
        ->and($adapter)->not->toBeInstanceOf(SupportsDelete::class);
});

test('SS-02: the whole inbound pipeline works on the second adapter unchanged — normalize, dedupe, identity, persist', function () {
    minimalFixture();
    $tenant = app(App\Support\Tenancy\TenantContext::class)->tenantId();

    Queue::fake([App\Jobs\Conversations\ConfirmBatchTail::class]);

    Bus::dispatchSync(new NormalizeInbound('minchan', 'line-1', [
        'id' => 88_001,
        'chat_ref' => 'min-room-9',
        'message_id' => 'min-e2e-1',
        'who' => ['ref' => 'min-user-9', 'label' => 'Fixture Minimal Customer', 'msisdn' => '+251900004321'],
        'kind' => 'text',
        'content' => 'hello through the minimal channel',
        'occurred_at' => '2026-08-05T09:00:00+03:00',
    ]));
    bindTenant($tenant);

    $message = Message::query()->where('external_message_id', 'min-e2e-1')->sole();
    $conversation = Conversation::query()->findOrFail($message->conversation_id);

    expect($message->body)->toBe('hello through the minimal channel')
        ->and($conversation->channel)->toBe('minchan')
        ->and($conversation->channel_ref_id)->toBe('line-1')
        ->and(Customer::query()->count())->toBe(1);
});

test('SS-02: the whole outbound pipeline works on the second adapter unchanged — compose, lane, rate gate, send, sent', function () {
    $adapter = minimalFixture();
    $context = app(App\Support\Tenancy\TenantContext::class);
    $tenant = $context->tenantId();

    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_minimal', 'is_shell' => true]);
    $conversation = app(ConversationRepository::class)
        ->findOrCreateForInbound('minchan', 'line-1', 'min-room-out', $customer->identifier());

    $message = app(ComposeOutbound::class)->compose($conversation, 'agent', null, 'text', 'reply over the minimal channel');
    bindTenant($tenant);

    app(LaneWorker::class)->processOnce(CarbonImmutable::now('UTC'));

    expect($message->refresh()->delivery_state)->toBe('sent')
        ->and(count($adapter->sent))->toBe(1)
        ->and((string) $adapter->sent[0]->payload->body)->toBe('reply over the minimal channel');
});

test('SS-02: the capability envelope binds the core, not conditionals — an over-320-char compose goes failed_final invalid_content', function () {
    $adapter = minimalFixture();
    $context = app(App\Support\Tenancy\TenantContext::class);
    $tenant = $context->tenantId();

    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_minimal', 'is_shell' => true]);
    $conversation = app(ConversationRepository::class)
        ->findOrCreateForInbound('minchan', 'line-1', 'min-room-cap', $customer->identifier());

    $message = app(ComposeOutbound::class)->compose($conversation, 'agent', null, 'text', str_repeat('አ', 321));
    bindTenant($tenant);

    app(LaneWorker::class)->processOnce(CarbonImmutable::now('UTC'));

    expect($message->refresh()->delivery_state)->toBe('failed_final')
        ->and($message->delivery_error['reason'] ?? null)->toBe('invalid_content')
        ->and(count($adapter->sent))->toBe(0);
});
