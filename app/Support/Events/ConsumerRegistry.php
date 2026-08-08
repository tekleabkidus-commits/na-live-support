<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Jobs\OutboxConsumer;
use App\Jobs\Tickets\TicketReopenConsumer;

/**
 * ADR 0011's dispatch map: event name → the DURABLE consumers the outbox relay
 * queues after a row is relayed.
 *
 * THIS IS A REGISTRY IN THE ADR's SENSE: adding a consumer is an entry here —
 * reviewed like any other registry change — never an edit to relay logic. The relay
 * reads the map and knows nothing about what any consumer does.
 *
 * THE CONSUMER CONTRACT. Every class listed here:
 *   - is a queued job constructed as `new Consumer($eventId)` — the event id is the
 *     ONLY payload, because consumers re-read the event row (and re-fetch anything
 *     it identifies) under their own tenant context, so nothing stale or private
 *     rides the queue (SEC-07's posture);
 *   - uses TenantAware: the relay stamps tenantId + correlationId from the outbox
 *     row (C-14 — the correlation survives the relay hop into the consumer);
 *   - is IDEMPOTENT ON event_id (C-09): the relay is at-least-once, the queue is
 *     at-least-once, and a re-driven dead-letter is the recovery path FS-02 promises.
 *
 * Inline EPHEMERAL consumers (Doc 04's RealtimePusher) are a different, legitimate
 * species and do not belong here — the ADR draws exactly that line.
 */
final class ConsumerRegistry
{
    /** @var array<string, list<class-string<OutboxConsumer>>> */
    private const MAP = [
        // Doc 06 FR-08 / EC-01 / ADR 0011's first entry: a customer message may
        // reopen its ticket, cancel an auto-close, or open a windowed successor.
        'message.received' => [TicketReopenConsumer::class],
    ];

    /** @return list<class-string<OutboxConsumer>> */
    public static function consumersFor(string $eventName): array
    {
        return self::MAP[$eventName] ?? [];
    }

    /** @return array<string, list<class-string<OutboxConsumer>>> */
    public static function map(): array
    {
        return self::MAP;
    }
}
