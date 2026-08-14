<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Jobs\Customers\RollupConsumer;
use App\Jobs\Inbox\ListProjectorConsumer;
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
        // Doc 07 T-01 (S07 step 2): the same event also feeds the list projector
        // (last activity, snippet, unread anchor).
        // Doc 08 FR-03 (S08 step 3): RollupConsumer joins the fan-out —
        //    an event names WHICH customer to recompute; values from truth
        //    (C-09 by construction; contract fixture doc08-events.json).
        'message.received' => [TicketReopenConsumer::class, \App\Jobs\Telegram\WindowReopenConsumer::class, ListProjectorConsumer::class, RollupConsumer::class],

        // ── Doc 07 T-01 (S07 step 2): the list-projector's durable
        //    subscriptions — 29 events total, each a TRIGGER naming which
        //    tickets to recompute from truth (values never come from
        //    payloads). C-09 proof + kill/re-drive drill: ListProjectorTest;
        //    replay-counting proof: ReplayCountingTest; the doc07.json
        //    consumer-contract fixture was tightened in this same commit.
        'message.sent' => [ListProjectorConsumer::class],
        'note.mentioned' => [ListProjectorConsumer::class],
        'ticket.created' => [ListProjectorConsumer::class, RollupConsumer::class],
        'ticket.status_changed' => [ListProjectorConsumer::class, RollupConsumer::class],
        'ticket.assigned' => [ListProjectorConsumer::class],
        'ticket.assignee_deactivated' => [ListProjectorConsumer::class],
        'ticket.priority_changed' => [ListProjectorConsumer::class],
        'ticket.subject_changed' => [ListProjectorConsumer::class],
        'ticket.tagged' => [ListProjectorConsumer::class],
        'ticket.untagged' => [ListProjectorConsumer::class],
        'ticket.transferred' => [ListProjectorConsumer::class],
        'ticket.escalated' => [ListProjectorConsumer::class],
        'ticket.deescalated' => [ListProjectorConsumer::class],
        'ticket.merged' => [ListProjectorConsumer::class],
        'ticket.unmerged' => [ListProjectorConsumer::class],
        'ticket.split' => [ListProjectorConsumer::class],
        'ticket.spam_marked' => [ListProjectorConsumer::class, RollupConsumer::class],
        'ticket.spam_unmarked' => [ListProjectorConsumer::class, RollupConsumer::class],
        'ticket.reopened' => [ListProjectorConsumer::class],
        'ticket.resolved' => [ListProjectorConsumer::class],
        'ticket.closed' => [ListProjectorConsumer::class],
        'ticket.follower_added' => [ListProjectorConsumer::class],
        'ticket.follower_removed' => [ListProjectorConsumer::class],
        'telegram.window.closing' => [ListProjectorConsumer::class],
        'telegram.window.expired' => [ListProjectorConsumer::class],
        'telegram.window.reopened' => [ListProjectorConsumer::class],
        'telegram.connection.revoked' => [ListProjectorConsumer::class],
        'telegram.connection.active' => [ListProjectorConsumer::class],

        // Doc 08 FR-22/T-03 (S08 step 3): the customer merge family — the
        //    rollup consumer recomputes survivor (and restored, on unmerge);
        //    Doc 07's projector consumption of customer.merged (T-03's list
        //    rewrite) lands with the merge saga at step 6, escalation #3.
        'customer.merged' => [RollupConsumer::class],
        'customer.unmerged' => [RollupConsumer::class],

        // Doc 07 FR-03 (S07 step 3): channel.health.changed v2's FIRST
        // consumer — the header indicator's feed (inherited row 5; A-22's
        // transition-only semantics consumed, never re-derived).
        'channel.health.changed' => [\App\Jobs\Inbox\HealthIndicatorConsumer::class],
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
