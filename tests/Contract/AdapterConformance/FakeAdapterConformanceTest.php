<?php

declare(strict_types=1);

namespace Tests\Contract\AdapterConformance;

use App\Domain\Conversations\Adapters\AdapterError;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ChannelAdapter;
use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Adapters\SendResult;
use Tests\Support\Adapters\FakeChannelAdapter;

/**
 * The fake reference adapter driven through the FULL conformance matrix (Doc 04 AC-01,
 * §21's fake-adapter-first recommendation): all 32 capability combinations (2⁴ boolean
 * flags × reply_window none/duration), every error classification, dedupe stability,
 * ordering purity. This suite passing IS the CI gate later adapters must pass;
 * "every shipped adapter" re-proof rows attach when Docs 05/13 ship theirs
 * (pre-agreed bucket).
 */
final class FakeAdapterConformanceTest extends AdapterConformanceCase
{
    protected function makeAdapter(Capabilities $capabilities): ChannelAdapter
    {
        return new FakeChannelAdapter($capabilities);
    }

    protected function capabilityProfiles(): iterable
    {
        foreach ([true, false] as $typing) {
            foreach ([true, false] as $edit) {
                foreach ([true, false] as $delete) {
                    foreach ([true, false] as $buttons) {
                        foreach ([ReplyWindow::none(), ReplyWindow::duration(86_400)] as $window) {
                            $label = sprintf(
                                'typing=%d edit=%d delete=%d buttons=%d window=%s',
                                (int) $typing,
                                (int) $edit,
                                (int) $delete,
                                (int) $buttons,
                                $window->isRestricted() ? $window->seconds.'s' : 'none',
                            );

                            yield $label => new Capabilities(
                                maxText: 256,
                                mediaTypes: ['photo', 'voice'],
                                supportsTyping: $typing,
                                supportsEdit: $edit,
                                supportsDelete: $delete,
                                supportsButtons: $buttons,
                                replyWindow: $window,
                            );
                        }
                    }
                }
            }
        }
    }

    protected function rawInbound(int $sequence, array $overrides = []): array
    {
        return array_merge([
            'update_id' => 900_000 + $sequence,
            'chat_id' => 'fixture-chat-'.($sequence % 3),
            'message_id' => 'fixture-in-'.$sequence,
            'from' => [
                'id' => 'fixture-user-7',
                'display' => 'Fixture Customer',
                'username' => '@fixture_customer',
                'phone' => '+251900001234',
            ],
            'kind' => 'text',
            'text' => 'fixture inbound #'.$sequence,
            'occurred_at' => '2026-08-04T10:00:00+03:00',
        ], $overrides);
    }

    protected function induceFailure(ChannelAdapter $adapter, DeliveryFailureReason $reason): bool
    {
        assert($adapter instanceof FakeChannelAdapter);
        $adapter->scriptResult(SendResult::failed(new AdapterError($reason)));

        return true;
    }
}
