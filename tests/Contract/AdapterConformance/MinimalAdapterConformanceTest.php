<?php

declare(strict_types=1);

namespace Tests\Contract\AdapterConformance;

use App\Domain\Conversations\Adapters\AdapterError;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ChannelAdapter;
use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Adapters\SendResult;
use Tests\Support\Adapters\MinimalChannelAdapter;

/**
 * SS-02 (ruling R-16): the SECOND fake adapter through the SAME conformance
 * suite. Per the abstract case's rule, a real-shaped adapter yields the
 * profile it genuinely has — here the minimal SMS-like envelope (320-char
 * text, no media, every optional capability off, 24 h reply window). The
 * zero-core-edits proof lives in SecondAdapterZeroCoreEditsTest and in the
 * introducing commit's diff (tests/ only).
 */
final class MinimalAdapterConformanceTest extends AdapterConformanceCase
{
    protected function makeAdapter(Capabilities $capabilities): ChannelAdapter
    {
        return new MinimalChannelAdapter($capabilities);
    }

    protected function capabilityProfiles(): iterable
    {
        yield 'minimal sms-like' => new Capabilities(
            maxText: 320,
            mediaTypes: [],
            supportsTyping: false,
            supportsEdit: false,
            supportsDelete: false,
            supportsButtons: false,
            replyWindow: ReplyWindow::duration(86_400),
        );
    }

    protected function rawInbound(int $sequence, array $overrides = []): array
    {
        return array_merge([
            'id' => 700_000 + $sequence,
            'chat_ref' => 'min-room-'.($sequence % 3),
            'message_id' => 'min-in-'.$sequence,
            'who' => [
                'ref' => 'min-user-4',
                'label' => 'Fixture Minimal Customer',
                'msisdn' => '+251900004321',
            ],
            'kind' => 'text',
            'content' => 'minimal inbound #'.$sequence,
            'occurred_at' => '2026-08-04T10:00:00+03:00',
        ], $overrides);
    }

    protected function induceFailure(ChannelAdapter $adapter, DeliveryFailureReason $reason): bool
    {
        assert($adapter instanceof MinimalChannelAdapter);
        $adapter->scriptResult(SendResult::failed(new AdapterError($reason)));

        return true;
    }
}
