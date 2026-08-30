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
        // Doc 09 FR-06/FR-10-step-6 (S09 step 6): ClassifyInboundMessage —
        //    the AI turn decision consumes the EVENT as a subscriber
        //    (Doc 04's fan-out contract; batch-tail debounced; idempotent on
        //    the message's existing classify decision). The ReopenConsumerDrillTest
        //    pin moves in THIS commit (the routine line).
        'message.received' => [TicketReopenConsumer::class, \App\Jobs\Telegram\WindowReopenConsumer::class, ListProjectorConsumer::class, RollupConsumer::class, \App\Jobs\Ai\ClassifyInboundMessage::class],

        // Doc 09 FR-05 (S09 step 6): the T-05 media hooks' subscribers —
        //    transcript/OCR write-back through the Doc 04 repository door;
        //    FS-03 leaves items untouched on provider outage.
        'media.needs_transcript' => [\App\Jobs\Ai\TranscribeVoiceConsumer::class],
        'media.needs_ocr' => [\App\Jobs\Ai\OcrReceiptConsumer::class],

        // ── Doc 07 T-01 (S07 step 2): the list-projector's durable
        //    subscriptions — 29 events total, each a TRIGGER naming which
        //    tickets to recompute from truth (values never come from
        //    payloads). C-09 proof + kill/re-drive drill: ListProjectorTest;
        //    replay-counting proof: ReplayCountingTest; the doc07.json
        //    consumer-contract fixture was tightened in this same commit.
        // S09 packet 2 (countersigned): Doc 09's notification categories reach the bell
        // through Doc 07's projector — kill-switch flips, anomaly alerts, digests.
        // ai.provider.health_changed is DELIBERATELY NOT projected (Doc 07's banner reads it).
        // S10 step 7b packet P4: the three kb notification arms (the A-44 amendment's events).
        'kb.variant.submitted' => [ListProjectorConsumer::class],
        'kb.variant.changes_requested' => [ListProjectorConsumer::class],
        'kb.lock.taken_over' => [ListProjectorConsumer::class],
        'ai.killswitch.changed' => [ListProjectorConsumer::class],
        'ai.anomaly.detected' => [ListProjectorConsumer::class],
        'ai.digest.ready' => [ListProjectorConsumer::class],
        'message.sent' => [ListProjectorConsumer::class],
        'note.mentioned' => [ListProjectorConsumer::class],
        // Doc 08 T-04 (S08 step 8): CustomerSearchIndexer rides the ticket
        //    events that change a customer's SCOPE arrays (assignee_ids /
        //    department_ids on the customers_index doc) — a reassigned-away
        //    agent keeping a customer in search would be a scope leak. The
        //    customer resolves from the ticket ROW (the RollupConsumer
        //    precedent), never the payload.
        // S09 step 11: Doc 06 FR-03's generation half — GenerateTicketSubject through SubjectWriter::generated() (Doc 06's own door).
        'ticket.created' => [ListProjectorConsumer::class, RollupConsumer::class, \App\Jobs\Customers\CustomerSearchIndexer::class, \App\Jobs\Ai\GenerateTicketSubject::class],
        // Doc 11 (S11 step 4): AssignmentStatsConsumer joins the four assignment-shaped ticket
        // events DELIBERATELY (FR-05/FR-06 counters, recompute-from-truth; contract fixture
        // fixtures/tickets/consumers/doc11.json tightened in the same commit).
        'ticket.status_changed' => [ListProjectorConsumer::class, RollupConsumer::class, \App\Jobs\Routing\AssignmentStatsConsumer::class],
        // ── Doc 10 FR-13/T-04/T-05 (S10 step 6): the index-maintenance consumer of
        //    kb.article.published — chunk → sanitize (the one door) → embed changed
        //    chunks → ONE-statement generation swap → kb.retrieval.updated. Idempotent
        //    on event_id (an already-serving version is a no-op); embeddings down →
        //    throws → retry/re-drive (FS-01). Contract fixture: fixtures/kb/consumers/
        //    doc10-events.json; ticket roster deliberately empty (doc10.json). This is
        //    the [D10] close of Doc 09 FR-10 (BS:57) and T-05 (BS:88).
        'kb.article.published' => [\App\Jobs\Kb\KbIndexMaintenanceConsumer::class, \App\Jobs\Kb\KbTranslationStateConsumer::class, \App\Jobs\Kb\KbSearchIndexer::class],
        // Doc 10 FR-17 (S10 step 10): the staff keyword index (Meilisearch) follows retirement too — the
        // catalog's Doc10/KbSearchIndexer on both; fixture rows in doc10-events.json SAME commit.
        'kb.retrieval.updated' => [\App\Jobs\Kb\KbSearchIndexer::class],
        // Doc 10 FR-04 (S10 step 8; A-45 AS RULED): the catalog's DECLARED tenant.provisioned
        // consumer, implemented — the starter set seeds right after activation (the disclosed
        // millisecond asynchrony). The ReopenConsumerDrillTest empty pin moves in THIS commit.
        'tenant.provisioned' => [\App\Jobs\Kb\KnowledgeBaseSeederConsumer::class],
        'ticket.assigned' => [ListProjectorConsumer::class, \App\Jobs\Customers\CustomerSearchIndexer::class, \App\Jobs\Routing\AssignmentStatsConsumer::class],
        'ticket.assignee_deactivated' => [ListProjectorConsumer::class, \App\Jobs\Customers\CustomerSearchIndexer::class, \App\Jobs\Routing\AssignmentStatsConsumer::class],
        'ticket.priority_changed' => [ListProjectorConsumer::class],
        'ticket.subject_changed' => [ListProjectorConsumer::class],
        'ticket.tagged' => [ListProjectorConsumer::class],
        'ticket.untagged' => [ListProjectorConsumer::class],
        'ticket.transferred' => [ListProjectorConsumer::class, \App\Jobs\Customers\CustomerSearchIndexer::class, \App\Jobs\Routing\AssignmentStatsConsumer::class],
        'ticket.escalated' => [ListProjectorConsumer::class],
        'ticket.deescalated' => [ListProjectorConsumer::class],
        'ticket.merged' => [ListProjectorConsumer::class],
        'ticket.unmerged' => [ListProjectorConsumer::class],
        'ticket.split' => [ListProjectorConsumer::class],
        'ticket.spam_marked' => [ListProjectorConsumer::class, RollupConsumer::class],
        'ticket.spam_unmarked' => [ListProjectorConsumer::class, RollupConsumer::class],
        // Doc 09 T-08 (S09 step 9): outcome enrichment joins ticket events to
        //    the AI decisions that created the ticket (ai_decisions.ticket_id);
        //    contract fixture fixtures/tickets/consumers/doc09.json; the
        //    ReopenConsumerDrillTest pin moves in THIS commit.
        'ticket.reopened' => [ListProjectorConsumer::class, \App\Jobs\Ai\AiOutcomeEnrichmentConsumer::class],
        'ticket.resolved' => [ListProjectorConsumer::class, \App\Jobs\Ai\AiOutcomeEnrichmentConsumer::class],
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
        // Doc 08 T-04 (S08 step 8): the search indexer joins — survivor
        //    reindexed, the tombstoned merged row REMOVED from the index
        //    (BR-05: tombstones redirect, they do not surface as results).
        // S08 escalation #3 part 2 (T-03/EC-01): the Doc 07 projector joins —
        //    the DECLARED consumer finally implemented; list rows carrying the
        //    merged customer recompute from the survivor ROW (truth, never
        //    payload). Routine line applied: doc07-events.json moved same-commit.
        'customer.merged' => [RollupConsumer::class, \App\Jobs\Customers\CustomerSearchIndexer::class, ListProjectorConsumer::class],
        'customer.unmerged' => [RollupConsumer::class, \App\Jobs\Customers\CustomerSearchIndexer::class],

        // Doc 08 T-04 (S08 step 8): the customers_index feed — the seven
        //    FR-22 events the catalog names Doc08/SearchIndexer on. Documents
        //    are rewritten from TRUTH per delivery (C-09 by construction).
        'customer.created' => [\App\Jobs\Customers\CustomerSearchIndexer::class],
        'customer.updated' => [\App\Jobs\Customers\CustomerSearchIndexer::class],
        'customer.identity.linked' => [\App\Jobs\Customers\CustomerSearchIndexer::class],
        'customer.identity.unlinked' => [\App\Jobs\Customers\CustomerSearchIndexer::class],
        'customer.fields_changed' => [\App\Jobs\Customers\CustomerSearchIndexer::class],

        // Doc 08 FR-18/T-06 (S08 step 7): the blocking cascade — quiets every
        //    conversation on block, restores exactly the cascade-blocked set
        //    on unblock (amendment addition 7's memory).
        'customer.blocked' => [\App\Jobs\Customers\BlockingCascadeConsumer::class],
        'customer.unblocked' => [\App\Jobs\Customers\BlockingCascadeConsumer::class],

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
