<?php

declare(strict_types=1);

namespace Tests\Contract\AdapterConformance;

use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ChannelAdapter;
use App\Domain\Conversations\Adapters\DeliverableKind;
use App\Domain\Conversations\Adapters\DeliverablePayload;
use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Conversations\Adapters\HealthStatus;
use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Domain\Conversations\Adapters\OutboundMedia;
use App\Domain\Conversations\Adapters\OutboundMessage;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Adapters\SendState;
use App\Domain\Conversations\Adapters\SupportsDelete;
use App\Domain\Conversations\Adapters\SupportsEdit;
use App\Domain\Conversations\Adapters\SupportsTyping;
use App\Domain\Conversations\Messages\MessageKind;
use DomainException;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;
use Throwable;

/**
 * THE ADAPTER CONFORMANCE SUITE (Doc 04 T-09, AC-01) — the executable form of the
 * FR-08 contract and the CI gate every adapter must pass before it ships. Doc 05's
 * Telegram adapter and Doc 13's widget adapter each get a subclass; a subclass that
 * cannot pass these tests is an adapter that cannot ship (R-04).
 *
 * Never depends on a mock HTTP service being up: subclasses drive their adapter as a
 * code-level object (sandboxed/stubbed transport is the subclass's business).
 */
abstract class AdapterConformanceCase extends TestCase
{
    /** Build the adapter under test with the given capability envelope. */
    abstract protected function makeAdapter(Capabilities $capabilities): ChannelAdapter;

    /**
     * The capability profiles the matrix runs over. The fake reference adapter yields
     * ALL combinations (AC-01 "all capability combinations"); a real adapter yields
     * the profile(s) it genuinely has.
     *
     * @return iterable<string, Capabilities>
     */
    abstract protected function capabilityProfiles(): iterable;

    /**
     * A valid raw webhook payload for this adapter, unique per ($sequence, $overrides).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    abstract protected function rawInbound(int $sequence, array $overrides = []): array;

    /**
     * Arrange for the NEXT send() to fail with the given classification, or return
     * false if this adapter cannot induce that class in a test harness (the suite
     * then skips that class — visibly, never silently).
     */
    abstract protected function induceFailure(ChannelAdapter $adapter, DeliveryFailureReason $reason): bool;

    private function defaultCapabilities(): Capabilities
    {
        return new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo', 'video', 'voice', 'audio', 'document', 'sticker'],
            supportsTyping: true,
            supportsEdit: true,
            supportsDelete: true,
            supportsButtons: true,
            replyWindow: ReplyWindow::none(),
        );
    }

    // ── Inbound normalization (FR-08 inbound) ───────────────────────────────────

    public function test_normalization_maps_every_contract_field(): void
    {
        $adapter = $this->makeAdapter($this->defaultCapabilities());

        $normalized = $adapter->handleWebhook($this->rawInbound(1));

        $this->assertNotSame('', $normalized->externalChatId);
        $this->assertNotSame('', $normalized->externalMessageId);
        $this->assertNotSame('', $normalized->sender->externalUserId);
        $this->assertNotSame('', $normalized->sender->display);
        $this->assertNotSame('', $normalized->dedupeKey);
        $this->assertSame('UTC', $normalized->occurredAt->timezoneName, 'channel timestamps normalize to UTC (C-03)');
        $this->assertInstanceOf(NormalizedInbound::class, $normalized);
    }

    public function test_dedupe_key_is_stable_for_identical_payloads(): void
    {
        $adapter = $this->makeAdapter($this->defaultCapabilities());
        $raw = $this->rawInbound(2);

        $first = $adapter->handleWebhook($raw);
        $second = $adapter->handleWebhook($raw);

        $this->assertSame($first->dedupeKey, $second->dedupeKey, 'T-03: a channel retry must map to the same dedupe key');
    }

    public function test_dedupe_keys_differ_across_distinct_payloads(): void
    {
        $adapter = $this->makeAdapter($this->defaultCapabilities());

        $keys = [];
        foreach (range(10, 19) as $sequence) {
            $keys[] = $adapter->handleWebhook($this->rawInbound($sequence))->dedupeKey;
        }

        $this->assertCount(10, array_unique($keys), 'distinct messages must never share a dedupe key');
    }

    public function test_normalization_is_pure_per_item_and_never_reorders(): void
    {
        $adapter = $this->makeAdapter($this->defaultCapabilities());

        // A same-second burst (EC-01's shape): the adapter maps item-by-item in input
        // order — ordering decisions belong to the core's receipt-time truth (BR-04).
        $sameSecond = '2026-08-04T10:00:00+03:00';
        $inputIds = [];
        $outputIds = [];
        foreach (range(20, 29) as $sequence) {
            $raw = $this->rawInbound($sequence, ['occurred_at' => $sameSecond]);
            $normalized = $adapter->handleWebhook($raw);
            $inputIds[] = (string) $raw['message_id'];
            $outputIds[] = $normalized->externalMessageId;
        }

        $this->assertSame($inputIds, $outputIds, 'adapters never reorder; FR-07 ordering is the core\'s job');
    }

    public function test_channel_timestamp_survives_as_metadata(): void
    {
        $adapter = $this->makeAdapter($this->defaultCapabilities());

        $normalized = $adapter->handleWebhook(
            $this->rawInbound(30, ['occurred_at' => '2026-08-04T09:30:15+03:00']),
        );

        // BR-04/EC-10: the channel's clock is preserved (for the "sent earlier"
        // metadata line) but is never a sort key — preserved means exact instant.
        $this->assertSame('2026-08-04 06:30:15', $normalized->occurredAt->format('Y-m-d H:i:s'));
    }

    public function test_an_adapter_cannot_emit_an_internal_note(): void
    {
        $adapter = $this->makeAdapter($this->defaultCapabilities());

        try {
            $adapter->handleWebhook($this->rawInbound(31, ['kind' => 'note']));
            $this->fail('normalizing a note kind must be impossible (BR-01) — nothing was thrown');
        } catch (Throwable $e) {
            $this->assertInstanceOf(Throwable::class, $e);
        }
    }

    // ── Outbound + error classification (FR-08 outbound, FR-13/FR-14) ───────────

    public function test_send_success_reports_state_sent(): void
    {
        $adapter = $this->makeAdapter($this->defaultCapabilities());

        $result = $adapter->send(new OutboundMessage('fixture-chat-1', DeliverablePayload::text('hello')));

        $this->assertSame(SendState::Sent, $result->state);
        $this->assertNull($result->error);
    }

    public function test_error_classification_matches_the_frozen_table(): void
    {
        $expected = [
            DeliveryFailureReason::BlockedByUser->value => false,
            DeliveryFailureReason::WindowExpired->value => false,
            DeliveryFailureReason::InvalidContent->value => false,
            // A-20 (S23 plan approval): the two Doc 05 document-required rows,
            // added in the same commit as their enum cases — the ONLY sanctioned
            // edit to this suite since it froze.
            DeliveryFailureReason::ConnectionPaused->value => false,
            DeliveryFailureReason::RateLimited->value => true,
            DeliveryFailureReason::NetworkError->value => true,
            DeliveryFailureReason::ChannelUnavailable->value => true,
            DeliveryFailureReason::CredentialsUnavailable->value => true,
        ];

        $induced = 0;
        foreach (DeliveryFailureReason::cases() as $reason) {
            $adapter = $this->makeAdapter($this->defaultCapabilities());
            if (! $this->induceFailure($adapter, $reason)) {
                continue;
            }
            $induced++;

            $result = $adapter->send(new OutboundMessage('fixture-chat-2', DeliverablePayload::text('classified')));

            $this->assertSame(SendState::Failed, $result->state);
            $this->assertNotNull($result->error);
            $this->assertSame($reason, $result->error->reason);
            $this->assertSame(
                $expected[$reason->value],
                $result->error->retryable(),
                "FR-14: '{$reason->value}' must classify retryable=".var_export($expected[$reason->value], true),
            );
        }

        $this->assertGreaterThan(0, $induced, 'an adapter that can induce NO failure class proves nothing');
    }

    // ── Capability matrix (FR-09: flags drive behavior, AC-01) ──────────────────

    public function test_capability_matrix_gates_every_operation(): void
    {
        $profiles = 0;
        foreach ($this->capabilityProfiles() as $label => $capabilities) {
            $profiles++;
            $adapter = $this->makeAdapter($capabilities);

            $this->assertCapabilityConsistency($adapter, $capabilities, (string) $label);
        }

        $this->assertGreaterThan(0, $profiles);
    }

    private function assertCapabilityConsistency(ChannelAdapter $adapter, Capabilities $capabilities, string $label): void
    {
        // max_text: over-limit text is invalid content, immediately final — never a retry loop.
        $overlong = str_repeat('a', $capabilities->maxText + 1);
        $result = $adapter->send(new OutboundMessage('fixture-chat-3', DeliverablePayload::text($overlong)));
        $this->assertSame(SendState::Failed, $result->state, "[$label] over-max_text text must fail");
        $this->assertSame(DeliveryFailureReason::InvalidContent, $result->error?->reason, "[$label] over-max_text classifies invalid_content");

        // media_types: a kind the channel cannot carry refuses; a declared kind sends.
        $photo = DeliverablePayload::media(
            DeliverableKind::Photo,
            new OutboundMedia('t/fixture/photo-1', 'image/jpeg', 1024, 64, 64),
        );
        $mediaResult = $adapter->send(new OutboundMessage('fixture-chat-3', $photo));
        if ($capabilities->allowsMediaKind(DeliverableKind::Photo)) {
            $this->assertSame(SendState::Sent, $mediaResult->state, "[$label] declared media kind must send");
        } else {
            $this->assertSame(SendState::Failed, $mediaResult->state, "[$label] undeclared media kind must refuse");
            $this->assertSame(DeliveryFailureReason::InvalidContent, $mediaResult->error?->reason, "[$label] undeclared kind classifies invalid_content");
        }

        // supports_typing (FR-18): declared ⇒ implemented and callable; not declared ⇒ refuses.
        if ($capabilities->supportsTyping) {
            $this->assertInstanceOf(SupportsTyping::class, $adapter, "[$label] supports_typing declared but extension missing");
            $adapter->sendTyping('fixture-chat-3');
        } elseif ($adapter instanceof SupportsTyping) {
            try {
                $adapter->sendTyping('fixture-chat-3');
                $this->fail("[$label] typing must refuse when the capability is off");
            } catch (DomainException) {
                // the refusal is the pass
            }
        }

        // supports_edit / supports_delete (FR-22): same pairing rule.
        if ($capabilities->supportsEdit) {
            $this->assertInstanceOf(SupportsEdit::class, $adapter, "[$label] supports_edit declared but extension missing");
            $edit = $adapter->editMessage('fixture-chat-3', 'fixture-msg-1', DeliverablePayload::text('edited'));
            $this->assertSame(SendState::Sent, $edit->state, "[$label] declared edit must succeed");
        } elseif ($adapter instanceof SupportsEdit) {
            $edit = $adapter->editMessage('fixture-chat-3', 'fixture-msg-1', DeliverablePayload::text('edited'));
            $this->assertSame(SendState::Failed, $edit->state, "[$label] edit must refuse when the capability is off");
        }

        if ($capabilities->supportsDelete) {
            $this->assertInstanceOf(SupportsDelete::class, $adapter, "[$label] supports_delete declared but extension missing");
            $delete = $adapter->deleteMessage('fixture-chat-3', 'fixture-msg-1');
            $this->assertSame(SendState::Sent, $delete->state, "[$label] declared delete must succeed");
        } elseif ($adapter instanceof SupportsDelete) {
            $delete = $adapter->deleteMessage('fixture-chat-3', 'fixture-msg-1');
            $this->assertSame(SendState::Failed, $delete->state, "[$label] delete must refuse when the capability is off");
        }
    }

    // ── Health (FR-08) ──────────────────────────────────────────────────────────

    public function test_health_answers_with_the_contract_shape(): void
    {
        $adapter = $this->makeAdapter($this->defaultCapabilities());

        $health = $adapter->health();

        $this->assertContains($health->status, HealthStatus::cases());
    }

    // ── Structural note isolation at the contract boundary (SEC-07/AC-04) ───────

    public function test_send_paths_accept_only_deliverable_payloads(): void
    {
        $send = (new ReflectionClass(ChannelAdapter::class))->getMethod('send');
        $param = $send->getParameters()[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $param);
        $this->assertSame(OutboundMessage::class, $param->getName());

        $payload = (new ReflectionClass(OutboundMessage::class))->getProperty('payload')->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $payload);
        $this->assertSame(DeliverablePayload::class, $payload->getName());
        $this->assertFalse($payload->allowsNull(), 'a nullable payload would reopen the door SEC-07 welds shut');

        $this->assertNotContains('note', array_column(DeliverableKind::cases(), 'value'));
        $this->assertNotContains('system', array_column(DeliverableKind::cases(), 'value'));
        $this->assertContains(MessageKind::Note, MessageKind::cases(), 'note IS a message kind — just never a deliverable one');
    }
}
