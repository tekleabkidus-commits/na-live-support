<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Outbound\DeliveryState;
use App\Domain\Conversations\Outbound\DeliveryStateMachine;
use App\Domain\Conversations\Outbound\IllegalDeliveryTransition;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| AC-02 — the FR-13 state machine matrix, BOTH halves (Doc 04 S20 step 4)
|--------------------------------------------------------------------------
|
| Every legal transition proven to move the row and emit its FR-26 event; every
| illegal pair proven REJECTED by IllegalDeliveryTransition — enumerated from the
| full 7×7 grid, not hand-picked, so a new edge quietly added to the table turns
| a cell red here. The absent sent→failed edge is EC-05's whole point.
*/

/** The FR-13 diagram, restated INDEPENDENTLY of the machine's own table. */
function legalDeliveryEdges(): array
{
    return [
        ['queued', 'sending'],
        ['queued', 'canceled'],
        ['sending', 'sent'],
        ['sending', 'failed_retryable'],
        ['sending', 'failed_final'],
        ['failed_retryable', 'sending'],
        ['failed_retryable', 'failed_final'],
        ['sent', 'delivered'],
        ['failed_final', 'queued'],
    ];
}

function deliveryFixture(): array
{
    $tenant = createTenant();
    bindTenant($tenant);

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new FakeChannelAdapter(new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo'],
            supportsTyping: false,
            supportsEdit: false,
            supportsDelete: false,
            supportsButtons: false,
            replyWindow: ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }

    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_dsm', 'is_shell' => true, 'created_source' => 'staff']);
    $conversation = app(ConversationRepository::class)
        ->findOrCreateForInbound('fakechan', 'bot-1', 'chat-dsm-'.uniqid(), $customer->identifier());

    return [$conversation, app(MessageRepository::class), app(DeliveryStateMachine::class)];
}

function outboundRowIn(string $state, $conversation, MessageRepository $messages): Message
{
    $message = $messages->persistOutbound($conversation, 'agent', null, 'text', 'fixture body');

    if ($state !== 'queued') {
        $messages->updateDeliveryState($message, $state); // repo door, fixture setup only
    }

    return $message;
}

test('AC-02 legal half: every FR-13 edge moves the row, with its event where one is named', function () {
    [$conversation, $messages, $machine] = deliveryFixture();

    $eventFor = [
        'sent' => 'message.sent',
        'delivered' => 'message.delivered',
        'failed_final' => 'message.failed',
        'canceled' => 'message.canceled',
        'queued' => 'message.queued',
    ];

    foreach (legalDeliveryEdges() as [$from, $to]) {
        $message = outboundRowIn($from, $conversation, $messages);

        $before = isset($eventFor[$to])
            ? DB::table('outbox')->where('name', $eventFor[$to])->count()
            : null;

        $machine->transition($message, DeliveryState::from($to), reason: 'network_error');

        expect($message->refresh()->delivery_state)->toBe($to, "edge {$from}→{$to} must move the row");

        if ($before !== null) {
            expect(DB::table('outbox')->where('name', $eventFor[$to])->count())
                ->toBe($before + 1, "edge {$from}→{$to} must emit {$eventFor[$to]}");
        }
    }
});

test('AC-02 illegal half: every pair OUTSIDE the diagram is rejected — enumerated, including sent→failed (EC-05)', function () {
    [$conversation, $messages, $machine] = deliveryFixture();

    $states = array_map(fn (DeliveryState $s): string => $s->value, DeliveryState::cases());
    $legal = legalDeliveryEdges();

    $tried = 0;
    foreach ($states as $from) {
        foreach ($states as $to) {
            if ($from === $to || in_array([$from, $to], $legal, true)) {
                continue;
            }

            $message = outboundRowIn($from, $conversation, $messages);

            try {
                $machine->transition($message, DeliveryState::from($to));
                $this->fail("illegal edge {$from}→{$to} was ACCEPTED — the frozen contract leaked an edge");
            } catch (IllegalDeliveryTransition) {
                $tried++;
            }

            expect($message->refresh()->delivery_state)->toBe($from, "rejected edge {$from}→{$to} must not move the row");
        }
    }

    // 7×7 minus 7 self-loops minus 9 legal edges = 33 rejected pairs, every one exercised.
    expect($tried)->toBe(33);
});

test('EC-05: a post-send channel invalidation is an ANNOTATION on the sent row — never a state change', function () {
    [$conversation, $messages, $machine] = deliveryFixture();

    $message = outboundRowIn('sent', $conversation, $messages);

    $machine->annotatePostSendFailed($message, ['reason' => 'channel_invalidated_late']);

    expect($message->refresh()->delivery_state)->toBe('sent')
        ->and($message->delivery_error['post_send_failed']['reason'] ?? null)->toBe('channel_invalidated_late');

    // The annotation path refuses pre-send rows — those belong to the machine.
    $queued = outboundRowIn('queued', $conversation, $messages);
    expect(fn () => $machine->annotatePostSendFailed($queued, ['reason' => 'x']))->toThrow(DomainException::class);
});

test('FR-13: inbound rows have no delivery lifecycle — the machine refuses them outright', function () {
    [$conversation, $messages, $machine] = deliveryFixture();

    $inbound = Message::query()->create([
        'conversation_id' => $conversation->identifier(),
        'direction' => 'in',
        'author_type' => 'customer',
        'kind' => 'text',
        'body' => 'inbound fixture',
    ]);

    expect(fn () => $machine->transition($inbound, DeliveryState::Sending))->toThrow(DomainException::class);
});
