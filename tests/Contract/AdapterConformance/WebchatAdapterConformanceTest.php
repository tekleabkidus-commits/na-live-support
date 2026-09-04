<?php

declare(strict_types=1);

namespace Tests\Contract\AdapterConformance;

use App\Domain\Conversations\Adapters\AdapterHealth;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ChannelAdapter;
use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Webchat\WebchatAdapter;
use App\Domain\Webchat\WebchatTransportError;
use Illuminate\Broadcasting\BroadcastException;

/**
 * THE SECOND REAL ADAPTER through the unmodified suite (Doc 13 AC-01 / T-09;
 * Doc 04 AC-01's "every shipped adapter" bucket firing for webchat; the
 * Doc 05 zero-core-edits standard re-proven: this commit adds this file and
 * app/Domain/Webchat + one provider line, and touches NO core file — the
 * captured diff enumeration in docs/evidence/S13-step1/ is the proof).
 *
 * Profiles are the DECLARED sets (a real adapter yields what it genuinely
 * has, per the base class): `webchat` (T-02 verbatim: photo/video/document,
 * typing on, edit/delete OFF, buttons on, no reply window) and
 * `webchat-voice` (the same envelope with the voice kind, the platform flag
 * on — proven BOTH states, [OA-voice]).
 *
 * rawInbound is the widget's POST /messages envelope with the suite's
 * contract knobs (message_id / occurred_at / kind) at top level — they ARE
 * the envelope's own keys, no mirroring needed. kind=note is refused by the
 * contract's constructor (BR-01/N-04).
 *
 * Failure classes the channel can genuinely produce: invalid_content
 * (capability refusal, natural), channel_unavailable (Reverb's events API
 * refusing — a BroadcastException), network_error (anything the transport
 * threw below that), rate_limited (a classified transport refusal carrying
 * retry_after). The four it cannot — blocked_by_user, window_expired,
 * connection_paused, credentials_unavailable — are refused BEFORE the
 * adapter (Doc 04's conversation/customer guards) or do not exist for a
 * socket channel (no reply window, no adapter-held credentials); the suite
 * skips them VISIBLY.
 */
final class WebchatAdapterConformanceTest extends AdapterConformanceCase
{
    private ?\Throwable $nextPushFailure = null;

    protected function makeAdapter(Capabilities $capabilities): ChannelAdapter
    {
        $this->nextPushFailure = null;

        return new WebchatAdapter(
            capabilities: $capabilities,
            push: function (string $visitorId, string $suffix, string $event, array $payload): void {
                if ($this->nextPushFailure !== null) {
                    $failure = $this->nextPushFailure;
                    $this->nextPushFailure = null;
                    throw $failure;
                }
            },
            health: static fn (): AdapterHealth => AdapterHealth::ok(),
        );
    }

    protected function capabilityProfiles(): iterable
    {
        yield 'webchat' => new Capabilities(
            maxText: 4000,
            mediaTypes: ['photo', 'video', 'document'],
            supportsTyping: true,
            supportsEdit: false,
            supportsDelete: false,
            supportsButtons: true,
            replyWindow: ReplyWindow::none(),
        );

        yield 'webchat-voice' => new Capabilities(
            maxText: 4000,
            mediaTypes: ['photo', 'video', 'document', 'voice'],
            supportsTyping: true,
            supportsEdit: false,
            supportsDelete: false,
            supportsButtons: true,
            replyWindow: ReplyWindow::none(),
        );
    }

    protected function rawInbound(int $sequence, array $overrides = []): array
    {
        return array_merge([
            'visitor_id' => '0199aaaa-0000-7000-8000-00000000d13f',
            'widget_id' => '0199aaaa-0000-7000-8000-00000000d130',
            'message_id' => 'cm_'.(40_000 + $sequence),
            'kind' => 'text',
            'body' => 'webchat inbound #'.$sequence,
            'occurred_at' => '2026-08-04T09:30:15+03:00',
            'display' => 'Fixture Visitor',
            'page_url' => 'https://customer.harness.localhost:8443/deposit',
        ], $overrides);
    }

    protected function induceFailure(ChannelAdapter $adapter, DeliveryFailureReason $reason): bool
    {
        assert($adapter instanceof WebchatAdapter);

        $this->nextPushFailure = match ($reason) {
            DeliveryFailureReason::ChannelUnavailable => new BroadcastException('Pusher error: 503 Service Unavailable.'),
            DeliveryFailureReason::NetworkError => new \RuntimeException('cURL error 7: connection refused'),
            DeliveryFailureReason::RateLimited => new WebchatTransportError(DeliveryFailureReason::RateLimited, 5),
            DeliveryFailureReason::InvalidContent => new WebchatTransportError(DeliveryFailureReason::InvalidContent),
            default => null,
        };

        return $this->nextPushFailure !== null;
    }
}
