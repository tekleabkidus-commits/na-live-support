<?php

declare(strict_types=1);

namespace Tests\Contract\AdapterConformance;

use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ChannelAdapter;
use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Telegram\Api\TelegramApiError;
use App\Domain\Telegram\Api\TelegramTransportError;
use App\Domain\Telegram\TelegramAdapter;
use RuntimeException;
use Tests\Support\Adapters\ScriptedTelegramApi;

/**
 * THE FIRST REAL ADAPTER through the unmodified suite (Doc 05 SS-04/T-09;
 * Doc 04 AC-01's re-proof bucket firing; R-16 made real). The suite gained
 * exactly the two A-20 expected-table rows this same commit — nothing else —
 * and the zero-core-edits proof is the step-3 commit's captured diff
 * enumeration (docs/evidence/S23-step3/).
 *
 * Profiles are the DECLARED sets per T-01 (a real adapter yields what it
 * genuinely has): telegram-direct (no reply window, buttons on) and
 * telegram-business (24 h window; buttons declared off — business chats
 * render the numbered-text fallback, FR-27/Doc 04 FR-09).
 *
 * rawInbound carries the suite's contract knobs (top-level message_id /
 * occurred_at / kind) mirrored into the real Telegram update shape; the
 * adapter ignores unknown top-level keys the way the Bot API convention
 * ignores unknown fields. kind=note yields a message with NO customer
 * content — the closest Telegram can come to expressing an internal note —
 * and the adapter must refuse it (BR-01/N-04).
 */
final class TelegramAdapterConformanceTest extends AdapterConformanceCase
{
    private ?ScriptedTelegramApi $lastApi = null;

    /** @var object{fail: bool} */
    private object $tokenBox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenBox = new class
        {
            public bool $fail = false;
        };
    }

    protected function makeAdapter(Capabilities $capabilities): ChannelAdapter
    {
        $this->lastApi = new ScriptedTelegramApi;
        $box = $this->tokenBox;

        return new TelegramAdapter(
            api: $this->lastApi,
            capabilities: $capabilities,
            botKey: 'conf-bot-8144038606',
            tokenResolver: static function () use ($box): string {
                if ($box->fail) {
                    throw new RuntimeException('scripted credentials failure (FS-03)');
                }

                return '8144038606:FAKECREDcccccccccccccccccccccccccc';
            },
        );
    }

    protected function capabilityProfiles(): iterable
    {
        yield 'telegram-direct' => new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo', 'voice', 'video', 'document', 'sticker'],
            supportsTyping: true,
            supportsEdit: true,
            supportsDelete: true,
            supportsButtons: true,
            replyWindow: ReplyWindow::none(),
        );

        yield 'telegram-business' => new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo', 'voice', 'video', 'document', 'sticker'],
            supportsTyping: true,
            supportsEdit: true,
            supportsDelete: true,
            supportsButtons: false,
            replyWindow: ReplyWindow::duration(86_400),
        );
    }

    protected function rawInbound(int $sequence, array $overrides = []): array
    {
        $occurredAt = isset($overrides['occurred_at']) && is_string($overrides['occurred_at'])
            ? $overrides['occurred_at']
            : '2026-08-04T10:00:00+03:00';
        $messageId = $overrides['message_id'] ?? (40_000 + $sequence);
        $kind = $overrides['kind'] ?? 'text';

        $message = [
            'message_id' => $messageId,
            'from' => [
                'id' => 900_000_100,
                'is_bot' => false,
                'first_name' => 'Fixture',
                'last_name' => 'Customer',
                'username' => 'fixture_customer',
            ],
            'chat' => ['id' => 900_000_100, 'type' => 'private'],
            'date' => (int) strtotime($occurredAt),
        ];

        if ($kind === 'text') {
            $message['text'] = 'telegram inbound #'.$sequence;
        }
        // Any other kind: no content field — the adapter must refuse (BR-01).

        return array_merge([
            'update_id' => 920_000_000 + $sequence,
            'message' => $message,
            // The suite's contract knobs, top-level (AdapterConformanceCase
            // reads raw['message_id'] directly); the adapter ignores unknown
            // top-level keys per Bot API convention.
            'message_id' => $messageId,
            'occurred_at' => $occurredAt,
            'kind' => $kind,
        ], $overrides);
    }

    protected function induceFailure(ChannelAdapter $adapter, DeliveryFailureReason $reason): bool
    {
        assert($adapter instanceof TelegramAdapter);
        assert($this->lastApi instanceof ScriptedTelegramApi);

        if ($reason === DeliveryFailureReason::CredentialsUnavailable) {
            $this->tokenBox->fail = true; // FS-03: token resolution itself fails

            return true;
        }

        $this->lastApi->script(match ($reason) {
            DeliveryFailureReason::BlockedByUser => TelegramApiError::fromResponse('sendMessage', 403, [
                'ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user',
            ]),
            DeliveryFailureReason::WindowExpired => TelegramApiError::fromResponse('sendMessage', 400, [
                'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: BUSINESS_REPLY_WINDOW_EXPIRED',
            ]),
            DeliveryFailureReason::ConnectionPaused => TelegramApiError::fromResponse('sendMessage', 400, [
                'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: BUSINESS_CONNECTION_PAUSED',
            ]),
            DeliveryFailureReason::InvalidContent => TelegramApiError::fromResponse('sendMessage', 400, [
                'ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities: Unmatched end tag",
            ]),
            DeliveryFailureReason::RateLimited => TelegramApiError::fromResponse('sendMessage', 429, [
                'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 5',
                'parameters' => ['retry_after' => 5],
            ]),
            DeliveryFailureReason::ChannelUnavailable => TelegramApiError::fromResponse('sendMessage', 502, [
                'ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway',
            ]),
            DeliveryFailureReason::NetworkError => TelegramTransportError::forMethod('sendMessage', 'ConnectException', 'connection refused'),
            DeliveryFailureReason::CredentialsUnavailable => throw new RuntimeException('handled above'),
        });

        return true;
    }
}
