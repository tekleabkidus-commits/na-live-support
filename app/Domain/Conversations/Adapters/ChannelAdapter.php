<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

/**
 * The Channel Adapter contract — Doc 04 FR-08, the NORMATIVE interface.
 *
 * This is a FROZEN PUBLIC CONTRACT the moment it ships (Doc 04 §21): every channel
 * (Doc 05 Telegram, Doc 13 widget, any future channel) implements exactly this, and the
 * core calls adapters ONLY through it (FR-09). Adapters never touch core tables; the
 * core never branches on a channel name (T-09 / invariant N-20 — the
 * no-channel-conditionals gate enforces). Changes require a series-wide review.
 *
 * Capability flags returned by capabilities() drive core behavior (composer limits,
 * button fallbacks, typing, edit/delete support, reply_window). Capability-gated
 * operations that FR-18 (typing) and FR-22 (staff edit/delete) require live on the
 * extension interfaces {@see SupportsTyping}, {@see SupportsEdit}, {@see SupportsDelete}
 * — declared by the corresponding capability flag, verified by the conformance suite
 * (T-09/AC-01). FR-08's four members stay exactly as drawn.
 *
 * The conformance suite in tests/Contract/AdapterConformance is the executable form of
 * this contract and the CI gate every later adapter must pass (AC-01).
 */
interface ChannelAdapter
{
    /**
     * Normalize one raw webhook payload (FR-08 inbound).
     *
     * Pure per-item: the adapter never reorders, batches, or drops — dedupe and
     * ordering are the core's job (FR-07, FR-10 step 2). The dedupe_key must be
     * stable: the same raw payload always yields the same key (T-03).
     *
     * @param  array<string, mixed>  $raw
     */
    public function handleWebhook(array $raw): NormalizedInbound;

    /**
     * Deliver one outbound message (FR-08 outbound).
     *
     * The ONLY door out of the platform toward a customer. Its parameter type is the
     * structural half of SEC-07/BR-01: OutboundMessage carries a DeliverablePayload,
     * and note content cannot produce one.
     */
    public function send(OutboundMessage $message): SendResult;

    /**
     * The channel's capability envelope (FR-08). Core behavior keys off these flags —
     * never off the channel's name (FR-09).
     */
    public function capabilities(): Capabilities;

    /**
     * Channel health, surfaced by FS-01's health events (`channel.health.changed`, A-4).
     */
    public function health(): AdapterHealth;
}
